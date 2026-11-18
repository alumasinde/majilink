package ke.co.majilink.data.api

import com.google.gson.FieldNamingPolicy
import com.google.gson.GsonBuilder
import com.google.gson.TypeAdapter
import com.google.gson.reflect.TypeToken
import com.google.gson.stream.JsonReader
import com.google.gson.stream.JsonToken
import com.google.gson.stream.JsonWriter
import ke.co.majilink.BuildConfig
import ke.co.majilink.data.SessionStore
import okhttp3.Authenticator
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import okhttp3.Route
import retrofit2.HttpException
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.io.IOException
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking

object ApiClient {

    /**
     * MySQL TINYINT(1) columns can reach the app as 0/1, and Gson's built-in adapter
     * throws on a number instead of coercing it — which takes down the whole screen
     * that happened to contain the field. Accept numbers and "0"/"1" strings too so
     * a backend slip degrades to a wrong-looking toggle, never a crash.
     */
    private val lenientBoolean = object : TypeAdapter<Boolean>() {
        override fun write(out: JsonWriter, value: Boolean?) {
            out.value(value ?: false)
        }

        override fun read(input: JsonReader): Boolean = when (input.peek()) {
            JsonToken.BOOLEAN -> input.nextBoolean()
            JsonToken.NUMBER -> input.nextDouble() != 0.0
            JsonToken.STRING -> input.nextString().trim()
                .let { it == "1" || it.equals("true", ignoreCase = true) }
            else -> {
                input.skipValue()
                false
            }
        }
    }

    val gson = GsonBuilder()
        .setFieldNamingPolicy(FieldNamingPolicy.LOWER_CASE_WITH_UNDERSCORES)
        .registerTypeAdapter(Boolean::class.javaPrimitiveType, lenientBoolean)
        .registerTypeAdapter(Boolean::class.javaObjectType, lenientBoolean)
        .create()

    lateinit var api: MajiApi
        private set

    /** Base in use right now. The token refresh call runs outside Retrofit, so it needs it too. */
    var baseUrl: String = BuildConfig.API_BASE
        private set

    private val jsonType = "application/json; charset=utf-8".toMediaType()

    fun init(apiBase: String) {
        baseUrl = if (apiBase.endsWith("/")) apiBase else "$apiBase/"
        val client = OkHttpClient.Builder()
            .connectTimeout(20, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .addInterceptor { chain ->
                val token = SessionStore.accessToken
                val request = chain.request().newBuilder()
                    .header("Accept", "application/json")
                    // Lets the backend gate old builds and tell them apart in logs.
                    .header("X-App-Version-Code", BuildConfig.VERSION_CODE.toString())
                    .header("X-App-Version-Name", BuildConfig.VERSION_NAME)
                    .header("X-Device-Uid", SessionStore.deviceUid)
                    .apply { if (!token.isNullOrBlank()) header("Authorization", "Bearer $token") }
                    .build()
                chain.proceed(request)
            }
            .authenticator(object : Authenticator {
                override fun authenticate(route: Route?, response: Response): Request? {
                    val path = response.request.url.encodedPath
                    // A rejected sign-in is a form error, not an expired session; retrying
                    // it with old tokens would hide the real message.
                    if (path.contains("/auth/")) return null
                    val sentToken = response.request.header("Authorization")?.removePrefix("Bearer ")
                    synchronized(this) {
                        val latest = SessionStore.accessToken
                        if (!latest.isNullOrBlank() && latest != sentToken) {
                            return response.request.newBuilder()
                                .header("Authorization", "Bearer $latest")
                                .build()
                        }
                        val refreshToken = SessionStore.refreshToken
                        if (refreshToken.isNullOrBlank()) {
                            // Nothing left to renew with: show the sign-in screen rather
                            // than a bearer-token banner on every tab.
                            if (!SessionStore.accessToken.isNullOrBlank()) SessionStore.forceExpire()
                            return null
                        }
                        val renewed = runBlocking { refreshBlocking(refreshToken) } ?: return null
                        return response.request.newBuilder()
                            .header("Authorization", "Bearer ${renewed.accessToken}")
                            .build()
                    }
                }
            })
            .build()

        api = Retrofit.Builder()
            .baseUrl(baseUrl)
            .client(client)
            .addConverterFactory(GsonConverterFactory.create(gson))
            .build()
            .create(MajiApi::class.java)
    }

    private fun refreshBlocking(refreshToken: String): AuthPayload? {
        val body = """{"refresh_token":"$refreshToken","device_uid":"${SessionStore.deviceUid}"}"""
            .toRequestBody(jsonType)
        val request = Request.Builder()
            .url(baseUrl + "auth/refresh")
            .post(body)
            .build()
        return try {
            OkHttpClient().newCall(request).execute().use { response ->
                if (!response.isSuccessful) {
                    // Only 4xx means the refresh token is dead. A 5xx or a proxy error is
                    // an outage, and wiping the session would log the user out over it.
                    if (response.code in 400..499) SessionStore.forceExpire()
                    return null
                }
                val raw = response.body?.string().orEmpty()
                val type = object : TypeToken<Envelope<AuthPayload>>() {}.type
                val envelope: Envelope<AuthPayload> = gson.fromJson(raw, type)
                val payload = envelope.data ?: return null
                SessionStore.save(payload)
                payload
            }
        } catch (e: IOException) {
            null
        }
    }

    /**
     * Wraps a Retrofit call: returns the unwrapped body or an [ApiException] that
     * carries the API's error code and field errors.
     */
    suspend fun <T> safe(block: suspend () -> Envelope<T>): Result<T> = try {
        val envelope = block()
        val data = envelope.data
        if (data == null) {
            Result.failure(ApiException(500, "empty_body", "The server returned an empty response."))
        } else {
            Result.success(data)
        }
    } catch (e: ApiException) {
        Result.failure(e)
    } catch (e: HttpException) {
        Result.failure(parseError(e))
    } catch (e: IOException) {
        Result.failure(
            ApiException(
                0,
                "network",
                "No connection. Check your data and try again."
            )
        )
    } catch (e: Exception) {
        // Never show a raw parser/library message: it names internals and helps nobody.
        System.err.println("MajiLink request failed: ${e::class.java.name}: ${e.message}")
        Result.failure(ApiException(500, "unexpected", "Something went wrong. Please try again."))
    }

    private fun parseError(e: HttpException): ApiException {
        val raw = try {
            e.response()?.errorBody()?.string()
        } catch (io: IOException) {
            null
        }
        val parsed = raw?.let {
            try {
                gson.fromJson(it, ApiErrorEnvelope::class.java)
            } catch (ignored: Exception) {
                null
            }
        }
        val error = parsed?.error
        return ApiException(
            status = e.code(),
            code = error?.code,
            message = error?.message ?: "Request failed (${e.code()}).",
            fields = error?.fields ?: emptyMap()
        )
    }
}
