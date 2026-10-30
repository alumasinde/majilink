package ke.co.majilink.data.api

import com.google.gson.FieldNamingPolicy
import com.google.gson.GsonBuilder
import com.google.gson.reflect.TypeToken
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

    val gson = GsonBuilder()
        .setFieldNamingPolicy(FieldNamingPolicy.LOWER_CASE_WITH_UNDERSCORES)
        .create()

    lateinit var api: MajiApi
        private set

    private val jsonType = "application/json; charset=utf-8".toMediaType()

    fun init() {
        val client = OkHttpClient.Builder()
            .connectTimeout(20, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .addInterceptor { chain ->
                val token = SessionStore.accessToken
                val request = if (token.isNullOrBlank()) {
                    chain.request()
                } else {
                    chain.request().newBuilder()
                        .header("Authorization", "Bearer $token")
                        .header("Accept", "application/json")
                        .build()
                }
                chain.proceed(request)
            }
            .authenticator(object : Authenticator {
                override fun authenticate(route: Route?, response: Response): Request? {
                    if (response.request.url.encodedPath.endsWith("/auth/refresh")) return null
                    val sentToken = response.request.header("Authorization")?.removePrefix("Bearer ")
                    synchronized(this) {
                        val latest = SessionStore.accessToken
                        if (!latest.isNullOrBlank() && latest != sentToken) {
                            return response.request.newBuilder()
                                .header("Authorization", "Bearer $latest")
                                .build()
                        }
                        val refreshToken = SessionStore.refreshToken ?: return null
                        val renewed = runBlocking { refreshBlocking(refreshToken) } ?: return null
                        return response.request.newBuilder()
                            .header("Authorization", "Bearer ${renewed.accessToken}")
                            .build()
                    }
                }
            })
            .build()

        api = Retrofit.Builder()
            .baseUrl(BuildConfig.API_BASE)
            .client(client)
            .addConverterFactory(GsonConverterFactory.create(gson))
            .build()
            .create(MajiApi::class.java)
    }

    private fun refreshBlocking(refreshToken: String): AuthPayload? {
        val body = """{"refresh_token":"$refreshToken","device_uid":"${SessionStore.deviceUid}"}"""
            .toRequestBody(jsonType)
        val request = Request.Builder()
            .url(BuildConfig.API_BASE + "auth/refresh")
            .post(body)
            .build()
        return try {
            OkHttpClient().newCall(request).execute().use { response ->
                if (!response.isSuccessful) {
                    SessionStore.clear()
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
        Result.failure(ApiException(500, "unexpected", e.message ?: "Something went wrong."))
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
