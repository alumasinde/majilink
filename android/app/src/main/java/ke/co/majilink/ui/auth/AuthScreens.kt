package ke.co.majilink.ui.auth

import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Email
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.MarkEmailRead
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material.icons.rounded.Sms
import androidx.compose.material.icons.rounded.WaterDrop
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import ke.co.majilink.data.SessionStore
import ke.co.majilink.ui.components.ErrorBanner
import ke.co.majilink.ui.components.LabeledField
import ke.co.majilink.ui.components.OtpInput
import ke.co.majilink.ui.components.PrimaryButton
import ke.co.majilink.ui.components.ScreenTopBar
import ke.co.majilink.ui.components.StepIndicator
import ke.co.majilink.ui.theme.MajiPalette
import kotlinx.coroutines.delay

@Composable
fun WelcomeScreen(onRegister: () -> Unit, onLogin: () -> Unit) {
    var shown by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { shown = true }

    Scaffold { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(horizontal = 24.dp),
            verticalArrangement = Arrangement.Center
        ) {
            AnimatedVisibility(
                visible = shown,
                enter = fadeIn(tween(320)) + slideInVertically(tween(340)) { it / 8 }
            ) {
                Column {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(
                            Modifier
                                .size(54.dp)
                                .background(MaterialTheme.colorScheme.primary, RoundedCornerShape(16.dp)),
                            contentAlignment = Alignment.Center
                        ) {
                            Icon(
                                Icons.Rounded.WaterDrop,
                                contentDescription = null,
                                tint = MaterialTheme.colorScheme.onPrimary,
                                modifier = Modifier.size(30.dp)
                            )
                        }
                        Spacer(Modifier.width(12.dp))
                        Column {
                            Text("MajiLink", fontSize = 24.sp, fontWeight = FontWeight.Bold)
                            Text(
                                "Pin it. Order it. Delivered.",
                                fontSize = 13.sp,
                                color = MaterialTheme.colorScheme.onSurfaceVariant
                            )
                        }
                    }
                    Spacer(Modifier.height(36.dp))
                    Text("Water at your gate", fontSize = 32.sp, fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(10.dp))
                    Text(
                        "See trusted vendors near your home, order 20-litre jerrycans and track the delivery to your pin.",
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        fontSize = 15.sp
                    )
                }
            }
            Spacer(Modifier.height(34.dp))
            AnimatedVisibility(
                visible = shown,
                enter = fadeIn(tween(360, delayMillis = 120)) +
                    slideInVertically(tween(360, delayMillis = 120)) { it / 4 }
            ) {
                Column {
                    PrimaryButton("Create an account", onRegister)
                    Spacer(Modifier.height(6.dp))
                    TextButton(onClick = onLogin, modifier = Modifier.fillMaxWidth()) {
                        Text("I already have an account")
                    }
                }
            }
        }
    }
}

@Composable
fun LoginScreen(
    viewModel: AuthViewModel,
    onSignedIn: () -> Unit,
    onRegister: () -> Unit,
    onBack: () -> Unit = {}
) {
    var identifier by remember { mutableStateOf(SessionStore.identifier.orEmpty()) }
    var password by remember { mutableStateOf("") }
    val state = viewModel.state

    LaunchedEffect(state.signedIn) {
        if (state.signedIn) onSignedIn()
    }

    Scaffold(
        topBar = { ScreenTopBar("Sign in", onBack = onBack) }
    ) { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 24.dp)
                .padding(top = 8.dp, bottom = 24.dp)
        ) {
            Text("Welcome back", fontSize = 28.sp, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(6.dp))
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
                keyboardType = KeyboardType.Email,
                leadingIcon = Icons.Rounded.Email,
                placeholder = "you@example.com or 0712345678"
            )
            Spacer(Modifier.height(14.dp))
            LabeledField(
                label = "Password",
                value = password,
                onValueChange = { password = it },
                isPassword = true,
                leadingIcon = Icons.Rounded.Lock,
                error = state.fieldErrors["password"],
                imeAction = ImeAction.Done,
                onDone = { viewModel.login(identifier, password) }
            )
            Spacer(Modifier.height(24.dp))
            PrimaryButton(
                text = if (state.busy) "Signing in…" else "Sign in",
                onClick = { viewModel.login(identifier, password) },
                enabled = identifier.isNotBlank() && password.isNotBlank(),
                loading = state.busy
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
    var step by remember { mutableIntStateOf(0) }
    var contact by remember { mutableStateOf("") }
    var code by remember { mutableStateOf("") }
    var firstName by remember { mutableStateOf("") }
    var lastName by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var pin by remember { mutableStateOf("") }
    var resendIn by remember { mutableIntStateOf(0) }
    val state = viewModel.state

    LaunchedEffect(state.signedIn) {
        if (state.signedIn) onSignedIn()
    }
    LaunchedEffect(state.codeSent) {
        if (state.codeSent) {
            step = 1
            resendIn = 30
        }
    }
    LaunchedEffect(resendIn) {
        if (resendIn > 0) {
            delay(1000)
            resendIn -= 1
        }
    }
    LaunchedEffect(state.verificationToken) {
        if (state.verificationToken != null) {
            email = email.ifBlank { contact.takeIf { it.contains("@") }.orEmpty() }
            step = 2
        }
    }
    LaunchedEffect(code) {
        if (step == 1 && code.length == 6 && !state.busy && state.verificationToken == null) {
            viewModel.verifyCode(state.identifier.ifBlank { contact }, code, "register")
        }
    }

    val isPhoneContact = contact.isNotEmpty() && !contact.contains("@")

    Scaffold(
        topBar = {
            ScreenTopBar(
                title = "Create account",
                onBack = {
                    when {
                        step == 0 -> onBack()
                        step == 1 -> {
                            step = 0
                            code = ""
                            viewModel.resetVerification()
                        }
                        else -> step = 1
                    }
                }
            )
        }
    ) { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 24.dp)
                .padding(top = 4.dp, bottom = 24.dp)
        ) {
            StepIndicator(step = step, total = 3)
            Spacer(Modifier.height(10.dp))
            Text(
                when (step) {
                    0 -> "Step 1 of 3 · Your contact"
                    1 -> "Step 2 of 3 · Verify"
                    else -> "Step 3 of 3 · Your details"
                },
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                fontSize = 13.sp,
                fontWeight = FontWeight.SemiBold
            )
            Spacer(Modifier.height(10.dp))
            ErrorBanner(state.error) { viewModel.dismissError() }

            AnimatedContent(
                targetState = step,
                transitionSpec = {
                    val forward = targetState > initialState
                    (slideInHorizontally(tween(240)) { if (forward) it / 3 else -it / 3 } +
                        fadeIn(tween(220))) togetherWith
                        (slideOutHorizontally(tween(200)) { if (forward) -it / 3 else it / 3 } +
                            fadeOut(tween(140)))
                },
                label = "registerStep"
            ) { current ->
                Column {
                    when (current) {
                        0 -> ContactStep(
                            contact = contact,
                            onContactChange = {
                                contact = it
                                viewModel.dismissError()
                            },
                            busy = state.busy,
                            onSend = {
                                viewModel.resetVerification()
                                viewModel.sendCode(contact, "register")
                            }
                        )

                        1 -> CodeStep(
                            contact = state.identifier.ifBlank { contact },
                            isPhone = isPhoneContact,
                            code = code,
                            onCodeChange = {
                                code = it
                                viewModel.dismissError()
                            },
                            error = state.fieldErrors["code"],
                            devCode = state.devCode,
                            busy = state.busy,
                            resendIn = resendIn,
                            onVerify = {
                                viewModel.verifyCode(state.identifier.ifBlank { contact }, code, "register")
                            },
                            onResend = {
                                code = ""
                                viewModel.sendCode(state.identifier.ifBlank { contact }, "register")
                            },
                            onChangeContact = {
                                step = 0
                                code = ""
                                viewModel.resetVerification()
                            }
                        )

                        else -> DetailsStep(
                            firstName = firstName,
                            onFirstNameChange = { firstName = it },
                            lastName = lastName,
                            onLastNameChange = { lastName = it },
                            phone = phone,
                            onPhoneChange = { phone = it },
                            email = email,
                            onEmailChange = { email = it },
                            password = password,
                            onPasswordChange = { password = it },
                            pin = pin,
                            onPinChange = { pin = it },
                            fieldErrors = state.fieldErrors,
                            busy = state.busy,
                            onSubmit = {
                                viewModel.register(firstName, lastName, phone, email, password, pin)
                            }
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun ContactStep(
    contact: String,
    onContactChange: (String) -> Unit,
    busy: Boolean,
    onSend: () -> Unit
) {
    Column {
        Text("How should we reach you?", fontSize = 20.sp, fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(6.dp))
        Text(
            "We will send a 6-digit code to confirm it is really you.",
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            fontSize = 14.sp
        )
        Spacer(Modifier.height(18.dp))
        LabeledField(
            label = "Email or phone number",
            value = contact,
            onValueChange = onContactChange,
            keyboardType = KeyboardType.Email,
            placeholder = "you@example.com",
            leadingIcon = Icons.Rounded.Email,
            imeAction = ImeAction.Done,
            onDone = onSend
        )
        Spacer(Modifier.height(22.dp))
        PrimaryButton(
            text = if (busy) "Sending code…" else "Send my code",
            onClick = onSend,
            enabled = contact.isNotBlank(),
            loading = busy
        )
    }
}

@Composable
private fun CodeStep(
    contact: String,
    isPhone: Boolean,
    code: String,
    onCodeChange: (String) -> Unit,
    error: String?,
    devCode: String?,
    busy: Boolean,
    resendIn: Int,
    onVerify: () -> Unit,
    onResend: () -> Unit,
    onChangeContact: () -> Unit
) {
    Column {
        Box(
            Modifier
                .size(52.dp)
                .background(MaterialTheme.colorScheme.primary.copy(alpha = 0.12f), CircleShape),
            contentAlignment = Alignment.Center
        ) {
            Icon(
                if (isPhone) Icons.Rounded.Sms else Icons.Rounded.MarkEmailRead,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.primary,
                modifier = Modifier.size(26.dp)
            )
        }
        Spacer(Modifier.height(14.dp))
        Text("Enter your code", fontSize = 20.sp, fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(6.dp))
        Text(
            "We sent a 6-digit code to $contact.",
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            fontSize = 14.sp
        )
        Spacer(Modifier.height(18.dp))
        OtpInput(
            value = code,
            onValueChange = onCodeChange,
            enabled = !busy,
            error = error != null
        )
        if (error != null) {
            Text(
                error,
                color = MajiPalette.Danger,
                fontSize = 12.sp,
                modifier = Modifier.padding(top = 6.dp, start = 4.dp)
            )
        }
        if (devCode != null) {
            Spacer(Modifier.height(12.dp))
            Surface(
                color = MaterialTheme.colorScheme.surfaceContainerHigh,
                shape = RoundedCornerShape(10.dp)
            ) {
                Text(
                    "Development build · code $devCode",
                    fontSize = 12.sp,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp)
                )
            }
        }
        Spacer(Modifier.height(20.dp))
        PrimaryButton(
            text = if (busy) "Checking code…" else "Verify code",
            onClick = onVerify,
            enabled = code.length == 6,
            loading = busy
        )
        Spacer(Modifier.height(4.dp))
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            TextButton(onClick = onResend, enabled = resendIn == 0 && !busy) {
                Text(if (resendIn > 0) "Resend code in ${resendIn}s" else "Resend code")
            }
            Spacer(Modifier.weight(1f))
            TextButton(onClick = onChangeContact, enabled = !busy) {
                Text("Change contact")
            }
        }
    }
}

@Composable
private fun DetailsStep(
    firstName: String,
    onFirstNameChange: (String) -> Unit,
    lastName: String,
    onLastNameChange: (String) -> Unit,
    phone: String,
    onPhoneChange: (String) -> Unit,
    email: String,
    onEmailChange: (String) -> Unit,
    password: String,
    onPasswordChange: (String) -> Unit,
    pin: String,
    onPinChange: (String) -> Unit,
    fieldErrors: Map<String, String>,
    busy: Boolean,
    onSubmit: () -> Unit
) {
    Column {
        Text("Almost done", fontSize = 20.sp, fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(6.dp))
        Text(
            "These details appear on your delivery and your receipts.",
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            fontSize = 14.sp
        )
        Spacer(Modifier.height(18.dp))
        Row(
            Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.spacedBy(10.dp)
        ) {
            LabeledField(
                "First name",
                firstName,
                onFirstNameChange,
                modifier = Modifier.weight(1f)
            )
            LabeledField(
                "Last name",
                lastName,
                onLastNameChange,
                modifier = Modifier.weight(1f)
            )
        }
        Spacer(Modifier.height(12.dp))
        LabeledField(
            "Phone number",
            phone,
            onPhoneChange,
            keyboardType = KeyboardType.Phone,
            placeholder = "0712345678",
            leadingIcon = Icons.Rounded.Phone,
            error = fieldErrors["phone"]
        )
        Spacer(Modifier.height(12.dp))
        LabeledField(
            "Email (optional)",
            email,
            onEmailChange,
            keyboardType = KeyboardType.Email,
            placeholder = "you@example.com",
            leadingIcon = Icons.Rounded.Email,
            error = fieldErrors["email"]
        )
        Spacer(Modifier.height(12.dp))
        LabeledField(
            "Password (8+ characters)",
            password,
            onPasswordChange,
            isPassword = true,
            leadingIcon = Icons.Rounded.Lock,
            error = fieldErrors["password"]
        )
        Spacer(Modifier.height(12.dp))
        LabeledField(
            "6-digit PIN for this phone",
            pin,
            { onPinChange(it.filter(Char::isDigit).take(6)) },
            keyboardType = KeyboardType.NumberPassword,
            isPassword = true,
            error = fieldErrors["pin"],
            imeAction = ImeAction.Done,
            onDone = onSubmit
        )
        Text(
            "Avoid 123456, repeated digits or sequences.",
            fontSize = 12.sp,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )
        Spacer(Modifier.height(22.dp))
        PrimaryButton(
            text = if (busy) "Creating account…" else "Create account",
            onClick = onSubmit,
            enabled = firstName.isNotBlank() && lastName.isNotBlank() &&
                phone.isNotBlank() && password.length >= 8 && pin.length == 6,
            loading = busy
        )
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
            Spacer(Modifier.height(26.dp))
            Box(
                Modifier
                    .size(64.dp)
                    .background(MaterialTheme.colorScheme.primary.copy(alpha = 0.14f), CircleShape),
                contentAlignment = Alignment.Center
            ) {
                Icon(
                    Icons.Rounded.Lock,
                    contentDescription = null,
                    tint = MaterialTheme.colorScheme.primary,
                    modifier = Modifier.size(30.dp)
                )
            }
            Spacer(Modifier.height(16.dp))
            Text("Welcome back", fontSize = 26.sp, fontWeight = FontWeight.Bold)
            Text(
                SessionStore.identifier.orEmpty(),
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                fontSize = 14.sp
            )
            Spacer(Modifier.height(22.dp))
            ErrorBanner(state.error) { viewModel.dismissError() }
            OtpInput(
                value = pin,
                onValueChange = {
                    pin = it
                    viewModel.dismissError()
                },
                enabled = !state.busy,
                error = state.fieldErrors["pin"] != null,
                masked = true
            )
            Spacer(Modifier.height(22.dp))
            PrimaryButton(
                text = if (state.busy) "Unlocking…" else "Unlock",
                onClick = { viewModel.loginWithPin(pin) },
                enabled = pin.length == 6,
                loading = state.busy
            )
            if (biometricAvailable && SessionStore.biometricGate) {
                Spacer(Modifier.height(6.dp))
                TextButton(onClick = { promptBiometric() }) {
                    Text("Use fingerprint", color = MaterialTheme.colorScheme.primary)
                }
            }
            Spacer(Modifier.height(4.dp))
            TextButton(onClick = onUsePassword, modifier = Modifier.fillMaxWidth()) {
                Text("Sign in with password instead")
            }
            Spacer(Modifier.height(14.dp))
            Text(
                "Five wrong PINs lock quick sign-in. Use your password to recover.",
                fontSize = 12.sp,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
        }
    }
}
