package ke.co.majilink.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp

enum class MajiRole { Customer, Vendor }

/** Brand palette from the specification. */
object MajiPalette {
    val CustomerPrimary = Color(0xFF0077B6)
    val CustomerAction = Color(0xFF2EC4B6)
    val VendorPrimary = Color(0xFF15803D)
    val VendorAction = Color(0xFF2EC4B6)
    val Danger = Color(0xFFB91C1C)
    val Warning = Color(0xFFF77F00)
    val Text = Color(0xFF0B132B)
    val LightBackground = Color(0xFFF8F9FA)
    val LightSurface = Color(0xFFFFFFFF)
    val DarkBackground = Color(0xFF1A1A24)
    val DarkSurface = Color(0xFF252533)
    val DarkText = Color(0xFFF8F9FA)
}

data class MajiExtraColors(
    val action: Color,
    val onAction: Color,
    val pending: Color = Color(0xFFB45309),
    val accepted: Color = Color(0xFF1D4ED8),
    val onTheWay: Color = Color(0xFF0F766E),
    val delivered: Color = Color(0xFF15803D),
    val cancelled: Color = Color(0xFFB91C1C)
)

val LocalMajiRole = staticCompositionLocalOf { MajiRole.Customer }
val LocalMajiExtraColors = staticCompositionLocalOf {
    MajiExtraColors(action = MajiPalette.CustomerAction, onAction = MajiPalette.Text)
}

private val CustomerLight = lightColorScheme(
    primary = MajiPalette.CustomerPrimary,
    onPrimary = Color.White,
    secondary = MajiPalette.CustomerAction,
    onSecondary = MajiPalette.Text,
    background = MajiPalette.LightBackground,
    onBackground = MajiPalette.Text,
    surface = MajiPalette.LightSurface,
    onSurface = MajiPalette.Text,
    error = MajiPalette.Danger,
    onError = Color.White
)

private val CustomerDark = darkColorScheme(
    primary = Color(0xFF57B9E8),
    onPrimary = Color(0xFF06202F),
    secondary = MajiPalette.CustomerAction,
    onSecondary = MajiPalette.Text,
    background = MajiPalette.DarkBackground,
    onBackground = MajiPalette.DarkText,
    surface = MajiPalette.DarkSurface,
    onSurface = MajiPalette.DarkText,
    error = Color(0xFFFF8A8A),
    onError = Color(0xFF2A0606)
)

private val VendorLight = lightColorScheme(
    primary = MajiPalette.VendorPrimary,
    onPrimary = Color.White,
    secondary = MajiPalette.VendorAction,
    onSecondary = MajiPalette.Text,
    background = MajiPalette.LightBackground,
    onBackground = MajiPalette.Text,
    surface = MajiPalette.LightSurface,
    onSurface = MajiPalette.Text,
    error = MajiPalette.Danger,
    onError = Color.White
)

private val VendorDark = darkColorScheme(
    primary = Color(0xFF4ADE80),
    onPrimary = Color(0xFF04321A),
    secondary = MajiPalette.VendorAction,
    onSecondary = MajiPalette.Text,
    background = MajiPalette.DarkBackground,
    onBackground = MajiPalette.DarkText,
    surface = MajiPalette.DarkSurface,
    onSurface = MajiPalette.DarkText,
    error = Color(0xFFFF8A8A),
    onError = Color(0xFF2A0606)
)

private val MajiTypography = Typography(
    bodyLarge = TextStyle(fontSize = 16.sp, lineHeight = 24.sp),
    bodyMedium = TextStyle(fontSize = 15.sp, lineHeight = 22.sp),
    titleLarge = TextStyle(fontSize = 22.sp, fontWeight = FontWeight.Bold, lineHeight = 28.sp),
    titleMedium = TextStyle(fontSize = 18.sp, fontWeight = FontWeight.SemiBold, lineHeight = 24.sp),
    labelLarge = TextStyle(fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
)

/**
 * One theme entry point. It swaps the colour scheme between customer (blue) and
 * vendor (green) modes so the two can never be confused.
 */
@Composable
fun MajiTheme(
    role: MajiRole = MajiRole.Customer,
    darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit
) {
    val scheme = when (role) {
        MajiRole.Customer -> if (darkTheme) CustomerDark else CustomerLight
        MajiRole.Vendor -> if (darkTheme) VendorDark else VendorLight
    }
    val extra = when (role) {
        MajiRole.Customer -> MajiExtraColors(
            action = MajiPalette.CustomerAction,
            onAction = MajiPalette.Text
        )
        MajiRole.Vendor -> MajiExtraColors(
            action = MajiPalette.VendorPrimary,
            onAction = Color.White
        )
    }

    CompositionLocalProvider(
        LocalMajiRole provides role,
        LocalMajiExtraColors provides extra
    ) {
        MaterialTheme(
            colorScheme = scheme,
            typography = MajiTypography,
            content = content
        )
    }
}
