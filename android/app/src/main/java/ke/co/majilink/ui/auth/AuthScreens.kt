package ke.co.majilink.ui.auth

import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.fragment.app.FragmentActivity
import androidx.core.content.ContextCompat
import ke.co.majilink.data.SessionStore
import ke.co.majilink.ui.components.ErrorBanner
import ke.co.majilink.ui.components.LabeledField
import ke.co.majilink.ui.components.PrimaryButton
@Composable
fun WelcomeScreen(onRegister: () -> Unit, onLogin: () -> Unit) {
    Scaffold { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(horizontal = 24.dp),
            verticalArrangement = Arrangement.Center
        ) {
            Surface(
                color = MaterialTheme.colorScheme.primary,
                shape = RoundedCornerShape(22.dp),
                modifier = Modifier.height(84.dp)
            ) {
                Column(Modifier.padding(20.dp)) {
                    Text("MajiLink", color = MaterialTheme.colorScheme.onPrimary, fontSize = 26.sp, fontWeight = FontWeight.Bold)
                    Text("Pin it. Order it. Delivered.", color = MaterialTheme.colorScheme.onPrimary, fontSize = 14.sp)
                }
            }
            Spacer(Modifier.height(28.dp))
            Text("Water at your gate", fontSize = 30.sp, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(10.dp))
            Text(
                "See trusted vendors near your home, order 20-litre jerrycans and track the delivery to your pin.",
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
            Spacer(Modifier.height(30.dp))
            PrimaryButton("Create an account", onRegister)
            Spacer(Modifier.height(10.dp))
            TextButton(onClick = onLogin, modifier = Modifier.fillMaxWidth()) {
                Text("I already have an account")
            }
        }
    }
}

@Composable
fun LoginScreen(
    viewModel: AuthViewModel,
    onSignedIn: () -> Unit,
    onRegister: () -> Unit
) {
    var identifier by remember { mutableStateOf(SessionStore.identifier.orEmpty()) }
    var password by remember { mutableStateOf("") }
    val state = viewModel.state

    LaunchedEffect(state.signedIn) {
        if (state.signedIn) onSignedIn()
    }

    Scaffold { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(24.dp)
        ) {
            Text("Sign in", fontSize = 28.sp, fontWeight = FontWeight.Bold)
            Text(
                "Use your phone or email and password. A new phone always starts with the password.",
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
            Spacer(Modifier.height(20.dp))
            ErrorBanner(state.error) { viewModel.dismissError() }
            LabeledField(
                label = "Phone or email",
                value = identifier,
                onValueChange = { identifier = it },
                keyboardType = KeyboardType.Email
            )
            Spacer(Modifier.height(12.dp))
            LabeledField(
                label = "Password",
                value = password,
                onValueChange = { password = it },
                isPassword = true,
                error = state.fieldErrors["password"]
            )
            Spacer(Modifier.height(22.dp))
            PrimaryButton(
                text = if (state.busy) "Signing in…" else "Sign in",
                onClick = { viewModel.login(identifier, password) },
                enabled = !state.busy
            )
            Spacer(Modifier.height(8.dp))
            TextButton(onClick = onRegister, modifier = Modifier.fillMaxWidth()) {
                Text("New to MajiLink? Create an account")
            }
        }
    }
}

/**
 * Registration follows the four app steps: contact, code, details + password,
 * PIN. Fingerprint is offered afterwards from Account.
 */
@Composable
fun RegisterScreen(
    viewModel: AuthViewModel,
    onSignedIn: () -> Unit,
    onBack: () -> Unit
) {
    var step by remember { mutableStateOf(0) }
    var contact by remember { mutableStateOf("") }
    var code by remember { mutableStateOf("") }
    var firstName by remember { mutableStateOf("") }
    var lastName by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var pin by remember { mutableStateOf("") }
    val state = viewModel.state

    LaunchedEffect(state.signedIn) {
        if (state.signedIn) onSignedIn()
    }
    LaunchedEffect(state.verificationToken) {
        if (state.verificationToken != null) step = 2
    }

    Scaffold { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(24.dp)
        ) {
            Text("Create your account", fontSize = 26.sp, fontWeight = FontWeight.Bold)
            Text(
                text = "Step ${step + 1} of 3",
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
            Spacer(Modifier.height(18.dp))
            ErrorBanner(state.error) { viewModel.dismissError() }

            when (step) {
                0 -> {
                    LabeledField(
                        label = "Email address",
                        value = contact,
                        onValueChange = { contact = it },
                        keyboardType = KeyboardType.Email,
                        placeholder = "you@example.com"
                    )
                    Spacer(Modifier.height(16.dp))
                    PrimaryButton(
                        text = if (state.busy) "Sending…" else "Send my code",
                        onClick = { viewModel.sendCode(contact, "register") },
                        enabled = !state.busy
                    )
                }

                1 -> {
                    Text("We sent a 6-digit code to ${state.identifier.ifBlank { contact }}.")
                    Spacer(Modifier.height(12.dp))
                    LabeledField(
                        label = "Verification code",
                        value = code,
                        onValueChange = { code = it.filter(Char::isDigit).take(6) },
                        keyboardType = KeyboardType.Number,
                        error = state.fieldErrors["code"]
                    )
                    if (state.devCode != null) {
                        Text(
                            "Local development code: ${state.devCode}",
                            fontSize = 12.sp,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                    Spacer(Modifier.height(16.dp))
                    PrimaryButton(
                        text = if (state.busy) "Checking…" else "Verify",
                        onClick = {
                            viewModel.verifyCode(state.identifier.ifBlank { contact }, code, "register")
                        },
                        enabled = !state.busy
                    )
                    TextButton(onClick = { step = 0; viewModel.dismissError() }) {
                        Text("Use a different contact")
                    }
                }

                else -> {
                    LabeledField("First name", firstName, { firstName = it })
                    Spacer(Modifier.height(10.dp))
                    LabeledField("Last name", lastName, { lastName = it })
                    Spacer(Modifier.height(10.dp))
                    LabeledField(
                        "Phone number",
                        phone,
                        { phone = it },
                        keyboardType = KeyboardType.Phone,
                        placeholder = "0712345678",
                        error = state.fieldErrors["phone"]
                    )
                    Spacer(Modifier.height(10.dp))
                    LabeledField(
                        "Email (optional if you verified by phone)",
                        contact.takeIf { it.contains("@") }.orEmpty(),
                        { contact = it },
                        keyboardType = KeyboardType.Email,
                        error = state.fieldErrors["email"]
                    )
                    Spacer(Modifier.height(10.dp))
                    LabeledField(
                        "Password (8+ characters)",
                        password,
                        { password = it },
                        isPassword = true,
                        error = state.fieldErrors["password"]
                    )
                    Spacer(Modifier.height(10.dp))
                    LabeledField(
                        "6-digit PIN for this phone",
                        pin,
                        { pin = it.filter(Char::isDigit).take(6) },
                        keyboardType = KeyboardType.Number,
                        isPassword = true,
                        error = state.fieldErrors["pin"]
                    )
                    Text(
                        "Avoid 123456, repeated digits or sequences.",
                        fontSize = 12.sp,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                    Spacer(Modifier.height(18.dp))
                    PrimaryButton(
                        text = if (state.busy) "Creating…" else "Create account",
                        onClick = {
                            viewModel.register(firstName, lastName, phone, contact, password, pin)
                        },
                        enabled = !state.busy
                    )
                }
            }

            Spacer(Modifier.height(8.dp))
            TextButton(onClick = { if (step == 0) onBack() else step -= 1 }, modifier = Modifier.fillMaxWidth()) {
                Text("Back")
            }
        }
    }
}

@Composable
fun PinLoginScreen(
    viewModel: AuthViewModel,
    onSignedIn: () -> Unit,
    onUsePassword: () -> Unit
) {
    var pin by remember { mutableStateOf("") }
    val state = viewModel.state
    val context = LocalContext.current

    LaunchedEffect(state.signedIn) {
        if (state.signedIn) onSignedIn()
    }

    val biometricAvailable = remember {
        BiometricManager.from(context).canAuthenticate(
            BiometricManager.Authenticators.BIOMETRIC_STRONG or BiometricManager.Authenticators.DEVICE_CREDENTIAL
        ) == BiometricManager.BIOMETRIC_SUCCESS
    }

    fun promptBiometric() {
        val activity = context as? FragmentActivity ?: return
        val prompt = BiometricPrompt(
            activity,
            ContextCompat.getMainExecutor(context),
            object : BiometricPrompt.AuthenticationCallback() {
                override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                    viewModel.biometricSignIn()
                }
            }
        )
        prompt.authenticate(
            BiometricPrompt.PromptInfo.Builder()
                .setTitle("Unlock MajiLink")
                .setSubtitle("Use your fingerprint or face to continue")
                .setAllowedAuthenticators(
                    BiometricManager.Authenticators.BIOMETRIC_STRONG or BiometricManager.Authenticators.DEVICE_CREDENTIAL
                )
                .build()
        )
    }

    LaunchedEffect(Unit) {
        if (SessionStore.biometricGate && biometricAvailable) {
            promptBiometric()
        }
    }

    Scaffold { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Spacer(Modifier.height(30.dp))
            Text("Welcome back", fontSize = 26.sp, fontWeight = FontWeight.Bold)
            Text(SessionStore.identifier.orEmpty(), color = MaterialTheme.colorScheme.onSurfaceVariant)
            Spacer(Modifier.height(24.dp))
            ErrorBanner(state.error) { viewModel.dismissError() }
            LabeledField(
                label = "PIN",
                value = pin,
                onValueChange = { pin = it.filter(Char::isDigit).take(6) },
                keyboardType = KeyboardType.NumberPassword,
                isPassword = true,
                error = state.fieldErrors["pin"]
            )
            Spacer(Modifier.height(18.dp))
            PrimaryButton(
                text = if (state.busy) "Checking…" else "Unlock",
                onClick = { viewModel.loginWithPin(pin) },
                enabled = !state.busy && pin.length == 6
            )
            if (biometricAvailable && SessionStore.biometricGate) {
                Spacer(Modifier.height(10.dp))
                TextButton(onClick = { promptBiometric() }) {
                    Text("Use fingerprint", color = MaterialTheme.colorScheme.primary)
                }
            }
            Spacer(Modifier.height(6.dp))
            TextButton(onClick = onUsePassword, modifier = Modifier.fillMaxWidth()) {
                Text("Sign in with password instead")
            }
            Spacer(Modifier.height(16.dp))
            Row(horizontalArrangement = Arrangement.Center) {
                Text(
                    "Five wrong PINs lock quick sign-in. Use your password to recover.",
                    fontSize = 12.sp,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
        }
    }
}
