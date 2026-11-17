package ke.co.majilink.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp

enum class MajiRole { Customer, Vendor }

/** Brand palette from the dark green specification. */
object MajiPalette {
    val Accent = Color(0xFF2BD989)
    val AccentDeep = Color(0xFF177A4E)
    val OnAccent = Color(0xFF06281B)
    val Danger = Color(0xFFFF6B6B)
    val Warning = Color(0xFFF5A623)
    val Text = Color(0xFF0B0F14)
    val LightBackground = Color(0xFFF8F9FA)
    val LightSurface = Color(0xFFFFFFFF)
    val DarkBackground = Color(0xFF0B0F14)
    val DarkSurface = Color(0xFF151D27)
    val DarkSurfaceHigh = Color(0xFF1D2733)
    val DarkText = Color(0xFFF1F5F8)
    val DarkTextMuted = Color(0xFF8E9AA8)
    val DarkOutline = Color(0xFF2A3542)
}

data class MajiExtraColors(
    val action: Color,
    val onAction: Color,
    val pending: Color = Color(0xFFF5C076),
    val accepted: Color = Color(0xFF6BB8FF),
    val onTheWay: Color = Color(0xFF4FD1C5),
    val delivered: Color = Color(0xFF4ADE80),
    val cancelled: Color = Color(0xFFFF8585)
)

val LocalMajiRole = staticCompositionLocalOf { MajiRole.Customer }
val LocalMajiExtraColors = staticCompositionLocalOf {
    MajiExtraColors(action = MajiPalette.Accent, onAction = MajiPalette.OnAccent)
}

private val MajiDark = darkColorScheme(
    primary = MajiPalette.Accent,
    onPrimary = MajiPalette.OnAccent,
    secondary = MajiPalette.Accent,
    onSecondary = MajiPalette.OnAccent,
    tertiary = MajiPalette.AccentDeep,
    background = MajiPalette.DarkBackground,
    onBackground = MajiPalette.DarkText,
    surface = MajiPalette.DarkSurface,
    onSurface = MajiPalette.DarkText,
    surfaceVariant = MajiPalette.DarkSurfaceHigh,
    onSurfaceVariant = MajiPalette.DarkTextMuted,
    outline = MajiPalette.DarkOutline,
    outlineVariant = MajiPalette.DarkOutline,
    error = MajiPalette.Danger,
    onError = MajiPalette.Text,
    surfaceContainer = MajiPalette.DarkSurface,
    surfaceContainerHigh = MajiPalette.DarkSurfaceHigh
)

private val MajiTypography = Typography(
    bodyLarge = TextStyle(fontSize = 16.sp, lineHeight = 24.sp),
    bodyMedium = TextStyle(fontSize = 15.sp, lineHeight = 22.sp),
    titleLarge = TextStyle(fontSize = 22.sp, fontWeight = FontWeight.Bold, lineHeight = 28.sp),
    titleMedium = TextStyle(fontSize = 18.sp, fontWeight = FontWeight.SemiBold, lineHeight = 24.sp),
    labelLarge = TextStyle(fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
)

/**
 * One theme entry point. The app ships a single dark green look for both
 * customer and vendor modes; the role only swaps the navigation and screens.
 */
@Composable
fun MajiTheme(
    role: MajiRole = MajiRole.Customer,
    content: @Composable () -> Unit
) {
    CompositionLocalProvider(
        LocalMajiRole provides role,
        LocalMajiExtraColors provides MajiExtraColors(
            action = MajiPalette.Accent,
            onAction = MajiPalette.OnAccent
        )
    ) {
        MaterialTheme(
            colorScheme = MajiDark,
            typography = MajiTypography,
            content = content
        )
    }
}
