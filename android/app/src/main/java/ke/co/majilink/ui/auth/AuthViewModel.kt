package ke.co.majilink.ui.auth

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import ke.co.majilink.data.MajiRepository
import ke.co.majilink.data.SessionStore
import ke.co.majilink.data.api.ApiException
import ke.co.majilink.data.api.AuthPayload
import kotlinx.coroutines.launch

data class AuthUiState(
    val busy: Boolean = false,
    val error: String? = null,
    val fieldErrors: Map<String, String> = emptyMap(),
    val codeSent: Boolean = false,
    val devCode: String? = null,
    val verificationToken: String? = null,
    val signedIn: Boolean = false,
    val identifier: String = ""
)

class AuthViewModel(
    private val repo: MajiRepository = MajiRepository()
) : ViewModel() {

    var state by mutableStateOf(AuthUiState())
        private set

    fun dismissError() {
        state = state.copy(error = null, fieldErrors = emptyMap())
    }

    /** Clears the one-shot signed-in signal after navigation has consumed it. */
    fun acknowledgeSignedIn() {
        state = state.copy(signedIn = false, busy = false)
    }

    fun sendCode(destination: String, purpose: String = "register") {
        if (destination.isBlank()) {
            state = state.copy(error = "Enter your email or phone number.")
            return
        }
        viewModelScope.launch {
            state = state.copy(
                busy = true,
                error = null,
                fieldErrors = emptyMap(),
                codeSent = false,
                devCode = null,
                verificationToken = null
            )
            repo.requestOtp(destination.trim(), purpose).fold(
                onSuccess = { challenge ->
                    state = state.copy(
                        busy = false,
                        codeSent = true,
                        devCode = challenge.devCode,
                        identifier = destination.trim(),
                        error = null,
                        fieldErrors = emptyMap()
                    )
                },
                onFailure = { showFailure(it) }
            )
        }
    }

    fun verifyCode(destination: String, code: String, purpose: String = "register") {
        if (code.length != 6) {
            state = state.copy(fieldErrors = mapOf("code" to "Enter the 6-digit code."))
            return
        }
        viewModelScope.launch {
            state = state.copy(busy = true, error = null, fieldErrors = emptyMap())
            repo.verifyOtp(destination.trim(), purpose, code).fold(
                onSuccess = {
                    state = state.copy(
                        busy = false,
                        verificationToken = it.verificationToken,
                        codeSent = false
                    )
                },
                onFailure = { showFailure(it) }
            )
        }
    }

    fun resetVerification() {
        state = state.copy(
            busy = false,
            codeSent = false,
            devCode = null,
            verificationToken = null,
            identifier = "",
            error = null,
            fieldErrors = emptyMap()
        )
    }

    fun register(
        firstName: String,
        lastName: String,
        phone: String,
        email: String,
        password: String,
        pin: String
    ) {
        val token = state.verificationToken
        if (token == null) {
            state = state.copy(error = "Verify your contact first.")
            return
        }
        viewModelScope.launch {
            state = state.copy(busy = true, error = null, fieldErrors = emptyMap())
            repo.register(
                verificationToken = token,
                firstName = firstName.trim(),
                lastName = lastName.trim(),
                phone = phone.trim(),
                email = email.trim().ifBlank { null },
                password = password,
                pin = pin
            ).fold(
                onSuccess = { finish(it, email.trim().ifBlank { phone.trim() }) },
                onFailure = { showFailure(it) }
            )
        }
    }

    fun login(identifier: String, password: String) {
        if (identifier.isBlank() || password.isBlank()) {
            state = state.copy(error = "Enter your phone or email and password.")
            return
        }
        viewModelScope.launch {
            state = state.copy(busy = true, error = null, fieldErrors = emptyMap())
            repo.login(identifier.trim(), password).fold(
                onSuccess = { finish(it, identifier.trim()) },
                onFailure = { showFailure(it) }
            )
        }
    }

    fun loginWithPin(pin: String) {
        val identifier = SessionStore.identifier
        if (identifier.isNullOrBlank()) {
            state = state.copy(error = "Sign in with your password once on this phone.")
            return
        }
        viewModelScope.launch {
            state = state.copy(busy = true, error = null)
            repo.loginWithPin(identifier, pin).fold(
                onSuccess = { finish(it, identifier) },
                onFailure = { showFailure(it) }
            )
        }
    }

    /** Called only after the OS confirms the fingerprint/face match. */
    fun biometricSignIn() {
        viewModelScope.launch {
            state = state.copy(busy = true, error = null)
            repo.refreshSession().fold(
                onSuccess = { finish(it, SessionStore.identifier.orEmpty()) },
                onFailure = { showFailure(it) }
            )
        }
    }

    fun setPin(pin: String, password: String? = null) {
        viewModelScope.launch {
            state = state.copy(busy = true, error = null, fieldErrors = emptyMap())
            repo.setPin(pin, password).fold(
                onSuccess = { state = state.copy(busy = false) },
                onFailure = { showFailure(it) }
            )
        }
    }

    fun setBiometric(enabled: Boolean) {
        viewModelScope.launch { repo.setBiometric(enabled) }
    }

    fun logout(onDone: () -> Unit) {
        viewModelScope.launch {
            runCatching { repo.logout() }
            SessionStore.clear()
            onDone()
        }
    }

    private fun finish(payload: AuthPayload, identifier: String) {
        if (payload.accessToken.isBlank()) {
            // Saving a blank token would make the app look signed in while every
            // request goes out unauthenticated.
            state = state.copy(busy = false, error = "Sign-in did not return a session. Please try again.")
            return
        }
        SessionStore.save(payload)
        SessionStore.identifier = identifier
        SessionStore.mode = "customer"
        state = state.copy(
            busy = false,
            signedIn = true,
            identifier = identifier,
            error = null
        )
    }

    private fun showFailure(error: Throwable) {
        val api = error as? ApiException
        state = state.copy(
            busy = false,
            error = api?.message ?: error.message ?: "Something went wrong.",
            fieldErrors = api?.fields ?: emptyMap()
        )
    }
}
