package ke.co.majilink

import android.os.Bundle
import androidx.activity.compose.setContent
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.fragment.app.FragmentActivity
import ke.co.majilink.data.SessionStore
import ke.co.majilink.ui.navigation.MajiNav
import ke.co.majilink.ui.theme.MajiRole
import ke.co.majilink.ui.theme.MajiTheme

/**
 * FragmentActivity (not just ComponentActivity) so BiometricPrompt can attach.
 * The whole app is one Compose tree whose theme follows the active mode.
 */
class MainActivity : FragmentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        SessionStore.init(applicationContext)

        setContent {
            var role by remember {
                mutableStateOf(
                    if (SessionStore.mode == "vendor" && SessionStore.hasVendorMode) {
                        MajiRole.Vendor
                    } else {
                        MajiRole.Customer
                    }
                )
            }

            MajiTheme(role = role) {
                MajiNav(role = role, onRoleChanged = { role = it })
            }
        }
    }
}
