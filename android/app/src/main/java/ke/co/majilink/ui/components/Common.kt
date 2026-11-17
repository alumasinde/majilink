package ke.co.majilink.ui.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Remove
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.foundation.text.KeyboardOptions
import ke.co.majilink.ui.theme.LocalMajiExtraColors
import ke.co.majilink.ui.theme.LocalMajiRole
import ke.co.majilink.ui.theme.MajiPalette
import ke.co.majilink.ui.theme.MajiRole

private val chipColors: Map<String, Pair<Color, Color>> = mapOf(
    "pending" to (Color(0xFFF5C076) to Color(0x33F5A623)),
    "accepted" to (Color(0xFF6BB8FF) to Color(0x336BB8FF)),
    "on the way" to (Color(0xFF4FD1C5) to Color(0x334FD1C5)),
    "delivered" to (Color(0xFF4ADE80) to Color(0x334ADE80)),
    "cancelled" to (Color(0xFFFF8585) to Color(0x33FF8585)),
    "declined" to (Color(0xFFFF8585) to Color(0x33FF8585)),
    "expired" to (Color(0xFFFF8585) to Color(0x33FF8585))
)

@Composable
fun StatusChip(status: String, modifier: Modifier = Modifier) {
    val pair = chipColors[status.lowercase()] ?: (MajiPalette.DarkTextMuted to Color(0x22FFFFFF))
    Surface(
        color = pair.second,
        contentColor = pair.first,
        shape = RoundedCornerShape(999.dp),
        border = BorderStroke(1.dp, pair.first.copy(alpha = 0.25f)),
        modifier = modifier
    ) {
        Text(
            text = status,
            fontSize = 12.sp,
            fontWeight = FontWeight.SemiBold,
            modifier = Modifier.padding(horizontal = 10.dp, vertical = 3.dp)
        )
    }
}

@Composable
fun PrimaryButton(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    icon: ImageVector? = null
) {
    Button(
        onClick = onClick,
        enabled = enabled,
        modifier = modifier
            .fillMaxWidth()
            .height(56.dp),
        shape = RoundedCornerShape(16.dp),
        colors = ButtonDefaults.buttonColors(
            containerColor = MaterialTheme.colorScheme.primary,
            contentColor = MaterialTheme.colorScheme.onPrimary
        )
    ) {
        if (icon != null) {
            Icon(icon, contentDescription = null, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(8.dp))
        }
        Text(text, fontSize = 17.sp, fontWeight = FontWeight.SemiBold)
    }
}

/** The teal Order button: always navy text, never white. */
@Composable
fun ActionButton(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true
) {
    val extra = LocalMajiExtraColors.current
    Button(
        onClick = onClick,
        enabled = enabled,
        modifier = modifier.height(56.dp),
        shape = RoundedCornerShape(14.dp),
        colors = ButtonDefaults.buttonColors(containerColor = extra.action, contentColor = extra.onAction)
    ) {
        Text(text, fontSize = 17.sp, fontWeight = FontWeight.Bold)
    }
}

@Composable
fun OutlineAction(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    danger: Boolean = false,
    icon: ImageVector? = null
) {
    val color = if (danger) MajiPalette.Danger else MaterialTheme.colorScheme.primary
    OutlinedButton(
        onClick = onClick,
        enabled = enabled,
        modifier = modifier.height(52.dp),
        shape = RoundedCornerShape(16.dp),
        border = BorderStroke(1.5.dp, color)
    ) {
        if (icon != null) {
            Icon(icon, contentDescription = null, tint = color, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(8.dp))
        }
        Text(text, color = color, fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
fun SectionCard(
    modifier: Modifier = Modifier,
    content: @Composable () -> Unit
) {
    Surface(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(18.dp),
        color = MaterialTheme.colorScheme.surface,
        tonalElevation = 1.dp,
        shadowElevation = 1.dp
    ) {
        Column(Modifier.padding(16.dp)) { content() }
    }
}

/** 20L jerrycan stepper used on the order sheet. */
@Composable
fun JerrycanStepper(
    quantity: Int,
    onChange: (Int) -> Unit,
    min: Int = 1,
    max: Int = 20
) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        IconButton(
            onClick = { if (quantity > min) onChange(quantity - 1) },
            modifier = Modifier
                .size(48.dp)
                .border(1.dp, MaterialTheme.colorScheme.outline.copy(alpha = 0.4f), CircleShape)
        ) {
            Icon(Icons.Rounded.Remove, contentDescription = "Fewer jerrycans")
        }
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            modifier = Modifier
                .width(110.dp)
                .padding(horizontal = 8.dp)
        ) {
            Text("$quantity", fontSize = 30.sp, fontWeight = FontWeight.Bold)
            Text("20L jerrycans", fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        IconButton(
            onClick = { if (quantity < max) onChange(quantity + 1) },
            modifier = Modifier
                .size(48.dp)
                .border(1.dp, MaterialTheme.colorScheme.outline.copy(alpha = 0.4f), CircleShape)
        ) {
            Icon(Icons.Rounded.Add, contentDescription = "More jerrycans")
        }
    }
}

@Composable
fun PriceLine(label: String, value: String, emphasise: Boolean = false) {
    Row(
        Modifier
            .fillMaxWidth()
            .padding(vertical = 4.dp),
        horizontalArrangement = Arrangement.SpaceBetween
    ) {
        Text(
            label,
            color = if (emphasise) MaterialTheme.colorScheme.onSurface else MaterialTheme.colorScheme.onSurfaceVariant
        )
        Text(
            value,
            fontWeight = if (emphasise) FontWeight.Bold else FontWeight.Normal,
            fontSize = if (emphasise) 22.sp else 16.sp
        )
    }
}

@Composable
fun LabeledField(
    label: String,
    value: String,
    onValueChange: (String) -> Unit,
    modifier: Modifier = Modifier,
    keyboardType: KeyboardType = KeyboardType.Text,
    isPassword: Boolean = false,
    placeholder: String? = null,
    singleLine: Boolean = true,
    error: String? = null
) {
    Column(modifier.fillMaxWidth()) {
        Text(label, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
        Spacer(Modifier.height(4.dp))
        OutlinedTextField(
            value = value,
            onValueChange = onValueChange,
            modifier = Modifier.fillMaxWidth(),
            placeholder = placeholder?.let { { Text(it) } },
            singleLine = singleLine,
            isError = error != null,
            visualTransformation = if (isPassword) PasswordVisualTransformation() else androidx.compose.ui.text.input.VisualTransformation.None,
            keyboardOptions = KeyboardOptions(keyboardType = keyboardType),
            shape = RoundedCornerShape(12.dp)
        )
        if (error != null) {
            Text(error, color = MajiPalette.Danger, fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp))
        }
    }
}

@Composable
fun ErrorBanner(message: String?, onDismiss: () -> Unit = {}) {
    if (message.isNullOrBlank()) return
    Surface(
        color = Color(0xFF3A1D20),
        contentColor = Color(0xFFFFB4B4),
        shape = RoundedCornerShape(12.dp),
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 8.dp)
    ) {
        Row(
            Modifier.padding(horizontal = 14.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Text(message, modifier = Modifier.weight(1f), fontSize = 14.sp)
            TextButton(onClick = onDismiss) { Text("Dismiss", color = Color(0xFFFFB4B4)) }
        }
    }
}

@Composable
fun OfflineBanner(visible: Boolean) {
    if (!visible) return
    Surface(color = MajiPalette.Warning, contentColor = MajiPalette.Text, modifier = Modifier.fillMaxWidth()) {
        Text(
            "You are offline. Showing the last saved data.",
            fontSize = 13.sp,
            fontWeight = FontWeight.SemiBold,
            textAlign = TextAlign.Center,
            modifier = Modifier
                .fillMaxWidth()
                .padding(vertical = 6.dp)
        )
    }
}

@Composable
fun EmptyState(
    title: String,
    body: String,
    icon: ImageVector? = null,
    action: (@Composable () -> Unit)? = null
) {
    Column(
        Modifier
            .fillMaxWidth()
            .padding(vertical = 40.dp, horizontal = 24.dp),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Box(
            Modifier
                .size(120.dp)
                .background(MajiPalette.Accent.copy(alpha = 0.10f), CircleShape),
            contentAlignment = Alignment.Center
        ) {
            if (icon != null) {
                Icon(
                    icon,
                    contentDescription = null,
                    tint = MajiPalette.Accent,
                    modifier = Modifier.size(56.dp)
                )
            }
        }
        Spacer(Modifier.height(18.dp))
        Text(title, fontWeight = FontWeight.Bold, fontSize = 18.sp)
        Spacer(Modifier.height(6.dp))
        Text(
            body,
            textAlign = TextAlign.Center,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )
        if (action != null) {
            Spacer(Modifier.height(20.dp))
            action()
        }
    }
}

/**
 * Plain dark screen header matching the design: optional back chevron, bold
 * title and an optional trailing icon/action on the right.
 */
@Composable
fun ScreenTopBar(
    title: String,
    onBack: (() -> Unit)? = null,
    trailing: (@Composable () -> Unit)? = null
) {
    Surface(color = MaterialTheme.colorScheme.background) {
        Row(
            Modifier
                .fillMaxWidth()
                .padding(horizontal = 16.dp, vertical = 14.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            if (onBack != null) {
                IconButton(onClick = onBack, modifier = Modifier.size(28.dp)) {
                    Icon(
                        Icons.AutoMirrored.Rounded.ArrowBack,
                        contentDescription = "Back",
                        tint = MaterialTheme.colorScheme.onSurface
                    )
                }
                Spacer(Modifier.width(10.dp))
            }
            Text(
                title,
                color = MaterialTheme.colorScheme.onSurface,
                fontWeight = FontWeight.Bold,
                fontSize = 20.sp,
                modifier = Modifier.weight(1f)
            )
            trailing?.invoke()
        }
    }
}

@Composable
fun ModeBadge(role: MajiRole) {
    val label = if (role == MajiRole.Vendor) "Vendor mode" else "Customer mode"
    Surface(
        color = MaterialTheme.colorScheme.primary.copy(alpha = 0.12f),
        contentColor = MaterialTheme.colorScheme.primary,
        shape = RoundedCornerShape(999.dp)
    ) {
        Text(
            label,
            fontSize = 12.sp,
            fontWeight = FontWeight.SemiBold,
            modifier = Modifier.padding(horizontal = 10.dp, vertical = 3.dp)
        )
    }
}
