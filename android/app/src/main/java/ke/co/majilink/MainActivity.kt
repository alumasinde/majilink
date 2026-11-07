package ke.co.majilink

import android.os.Bundle
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.fragment.app.FragmentActivity
import ke.co.majilink.data.AppGate
import ke.co.majilink.data.Gate
import ke.co.majilink.data.ServerStore
import ke.co.majilink.data.SessionStore
import ke.co.majilink.ui.gate.MaintenanceScreen
import ke.co.majilink.ui.gate.ServerSettingsScreen
import ke.co.majilink.ui.gate.UpdateScreen
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
            var showServer by remember { mutableStateOf(false) }

            MajiTheme(role = role) {
                val gate = AppGate.state
                Box(Modifier.fillMaxSize()) {
                    when (gate) {
                        is Gate.Maintenance -> MaintenanceScreen(gate.message) { showServer = true }
                        is Gate.Update -> UpdateScreen(gate) { showServer = true }
                        Gate.Ready -> MajiNav(role = role, onRoleChanged = { role = it })
                    }

                    if (gate == Gate.Ready && !showServer && ServerStore.canOverrideBase) {
                        TextButton(
                            onClick = { showServer = true },
                            modifier = Modifier.align(Alignment.BottomEnd).padding(10.dp)
                        ) {
                            Text("Server: ${ServerStore.hostLabel}", fontSize = 11.sp)
                        }
                    }

                    if (showServer) {
                        ServerSettingsScreen(onClose = { showServer = false })
                    }
                }
            }
        }
    }
}
