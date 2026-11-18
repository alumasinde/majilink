package ke.co.majilink.data

import android.content.Context
import android.content.SharedPreferences
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.setValue
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import ke.co.majilink.data.api.ApiClient
import ke.co.majilink.data.api.AuthPayload
import ke.co.majilink.data.api.UserDto
import java.util.UUID

/**
 * Tokens live in EncryptedSharedPreferences (AES-256, key held in the Android
 * Keystore). Biometric data never leaves the device: the fingerprint only guards
 * the session token via BiometricPrompt.
 */
object SessionStore {

    private const val PREFS = "majilink_session"
    private const val KEY_ACCESS = "access_token"
    private const val KEY_REFRESH = "refresh_token"
    private const val KEY_DEVICE = "device_uid"
    private const val KEY_IDENTIFIER = "identifier"
    private const val KEY_USER = "user_json"
    private const val KEY_MODE = "mode"
    private const val KEY_BIOMETRIC = "biometric_gate"

    private lateinit var prefs: SharedPreferences

    fun init(context: Context) {
        if (::prefs.isInitialized) return
        val masterKey = MasterKey.Builder(context.applicationContext)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()
        prefs = EncryptedSharedPreferences.create(
            context.applicationContext,
            PREFS,
            masterKey,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
        )
    }

    var accessToken: String?
        get() = prefs.getString(KEY_ACCESS, null)
        set(value) = prefs.edit().putString(KEY_ACCESS, value).apply()

    var refreshToken: String?
        get() = prefs.getString(KEY_REFRESH, null)
        set(value) = prefs.edit().putString(KEY_REFRESH, value).apply()

    var identifier: String?
        get() = prefs.getString(KEY_IDENTIFIER, null)
        set(value) = prefs.edit().putString(KEY_IDENTIFIER, value).apply()

    var mode: String
        get() = prefs.getString(KEY_MODE, "customer") ?: "customer"
        set(value) = prefs.edit().putString(KEY_MODE, value).apply()

    var biometricGate: Boolean
        get() = prefs.getBoolean(KEY_BIOMETRIC, false)
        set(value) = prefs.edit().putBoolean(KEY_BIOMETRIC, value).apply()

    val deviceUid: String
        get() {
            val existing = prefs.getString(KEY_DEVICE, null)
            if (existing != null) return existing
            val generated = UUID.randomUUID().toString()
            prefs.edit().putString(KEY_DEVICE, generated).apply()
            return generated
        }

    val deviceName: String
        get() = "${android.os.Build.MANUFACTURER} ${android.os.Build.MODEL}".trim()

    fun save(payload: AuthPayload) {
        prefs.edit()
            .putString(KEY_ACCESS, payload.accessToken)
            .putString(KEY_REFRESH, payload.refreshToken)
            .putString(KEY_USER, ApiClient.gson.toJson(payload.user))
            .apply()
    }

    fun user(): UserDto? {
        val raw = prefs.getString(KEY_USER, null) ?: return null
        return try {
            ApiClient.gson.fromJson(raw, UserDto::class.java)
        } catch (e: Exception) {
            null
        }
    }

    val isLoggedIn: Boolean
        get() = !accessToken.isNullOrBlank()

    val hasVendorMode: Boolean
        get() = user()?.modes?.contains("vendor") == true

    /**
     * Bumped when the server refuses to renew the session. The nav tree is keyed on
     * this, so screens holding failed requests are discarded and the user is taken
     * straight to sign-in instead of staring at 401 banners on every tab.
     */
    var signedOutEpoch by mutableIntStateOf(0)
        private set

    /** Drops the session but keeps the device identity, mode and biometric preference. */
    fun forceExpire() {
        prefs.edit().remove(KEY_ACCESS).remove(KEY_REFRESH).remove(KEY_USER).apply()
        signedOutEpoch++
    }

    fun clear() {
        prefs.edit().clear().apply()
    }
}
