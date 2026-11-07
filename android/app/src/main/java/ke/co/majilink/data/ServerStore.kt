package ke.co.majilink.data

import android.content.Context
import android.content.SharedPreferences
import com.google.gson.reflect.TypeToken
import ke.co.majilink.BuildConfig
import ke.co.majilink.data.api.ApiClient
import ke.co.majilink.data.api.EndpointConfig
import ke.co.majilink.data.api.Envelope
import ke.co.majilink.data.api.RuntimeConfig
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.OkHttpClient
import okhttp3.Request
import java.util.concurrent.TimeUnit

/**
 * Decides which backend this build talks to. Resolution order: an override saved on
 * the Server screen, then a base URL adopted from a config fetch, then the
 * compile-time default. A failed fetch leaves the previous value in place, so a dead
 * config endpoint cannot brick the app.
 */
object ServerStore {

    private const val PREFS = "majilink_server"
    private const val KEY_OVERRIDE = "api_base_override"
    private const val KEY_ADOPTED = "api_base_adopted"
    private const val KEY_CONFIG = "runtime_config"

    private lateinit var prefs: SharedPreferences

    private val configType = object : TypeToken<Envelope<RuntimeConfig>>() {}.type

    private val http by lazy {
        OkHttpClient.Builder()
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(10, TimeUnit.SECONDS)
            .build()
    }

    var config: RuntimeConfig? = null
        private set

    var lastError: String? = null
        private set

    fun init(context: Context) {
        if (::prefs.isInitialized) return
        prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        config = decode(prefs.getString(KEY_CONFIG, null))
    }

    val effectiveBase: String
        get() {
            val stored = if (::prefs.isInitialized) {
                prefs.getString(KEY_OVERRIDE, null) ?: prefs.getString(KEY_ADOPTED, null)
            } else {
                null
            }
            return (stored ?: BuildConfig.API_BASE).ensureSlash()
        }

    val isOverridden: Boolean
        get() = ::prefs.isInitialized && !prefs.getString(KEY_OVERRIDE, null).isNullOrBlank()

    /** Short host name for the on-screen affordance. */
    val hostLabel: String
        get() = effectiveBase.toHttpUrlOrNull()?.host ?: effectiveBase

    /** Re-pointing the app sends session tokens wherever it points, so it stays a developer affordance. */
    val canOverrideBase: Boolean
        get() = BuildConfig.DEBUG || config?.app?.allowEndpointOverride == true

    fun setOverride(raw: String): String? {
        val normalized = normalizeBase(raw) ?: return null
        prefs.edit().putString(KEY_OVERRIDE, normalized).apply()
        ApiClient.init(effectiveBase)
        return normalized
    }

    fun clearOverride() {
        prefs.edit().remove(KEY_OVERRIDE).apply()
        ApiClient.init(effectiveBase)
    }

    suspend fun refresh(): RuntimeConfig? = withContext(Dispatchers.IO) {
        val base = effectiveBase
        val request = Request.Builder()
            .url(base + "config")
            .header("Accept", "application/json")
            .header("X-App-Version-Code", BuildConfig.VERSION_CODE.toString())
            .header("X-App-Version-Name", BuildConfig.VERSION_NAME)
            .build()
        val fetched = try {
            http.newCall(request).execute().use { response ->
                val raw = response.body?.string().orEmpty()
                when {
                    !response.isSuccessful -> {
                        lastError = "Server replied ${response.code}"
                        null
                    }
                    else -> runCatching {
                        ApiClient.gson.fromJson<Envelope<RuntimeConfig>>(raw, configType)?.data
                    }.getOrNull().also { if (it == null) lastError = "Config could not be read" }
                }
            }
        } catch (e: Exception) {
            lastError = e.message ?: "Could not reach the server"
            null
        }
        if (fetched != null) {
            lastError = null
            config = fetched
            if (::prefs.isInitialized) {
                prefs.edit().putString(KEY_CONFIG, ApiClient.gson.toJson(fetched)).apply()
            }
            adopt(fetched.endpoint, base)
        }
        AppGate.evaluate()
        fetched
    }

    private fun decode(raw: String?): RuntimeConfig? = raw?.let {
        runCatching { ApiClient.gson.fromJson(it, RuntimeConfig::class.java) }.getOrNull()
    }

    /**
     * Follows the base URL the server advertises only while it stays on the host the
     * app already trusted: a response must not move session tokens to another domain.
     */
    private fun adopt(endpoint: EndpointConfig?, fetchedFrom: String) {
        if (!::prefs.isInitialized || isOverridden) return
        val advertised = normalizeBase(endpoint?.apiBaseUrl.orEmpty()) ?: return
        val from = fetchedFrom.toHttpUrlOrNull() ?: return
        val to = advertised.toHttpUrlOrNull() ?: return
        if (!from.host.equals(to.host, ignoreCase = true)) return
        if (!BuildConfig.DEBUG && !to.isHttps) return
        if (effectiveBase == advertised) return
        prefs.edit().putString(KEY_ADOPTED, advertised).apply()
        ApiClient.init(effectiveBase)
    }

    private fun normalizeBase(raw: String): String? {
        val trimmed = raw.trim()
        if (trimmed.isEmpty()) return null
        val candidate = when {
            trimmed.contains("://") -> trimmed
            BuildConfig.DEBUG -> "http://$trimmed"
            else -> "https://$trimmed"
        }
        val url = candidate.toHttpUrlOrNull() ?: return null
        if (url.scheme != "http" && url.scheme != "https") return null
        val path = url.encodedPath.trimEnd('/')
        val withApi = if (path.contains("/api/v1")) path else "$path/api/v1"
        return url.newBuilder().encodedPath(withApi).build().toString().ensureSlash()
    }

    private fun String.ensureSlash(): String = if (endsWith("/")) this else "$this/"
}
