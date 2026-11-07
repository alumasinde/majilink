package ke.co.majilink.ui.gate

import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import ke.co.majilink.BuildConfig
import ke.co.majilink.data.AppGate
import ke.co.majilink.data.Gate
import ke.co.majilink.data.ServerStore
import ke.co.majilink.data.SessionStore
import ke.co.majilink.ui.components.ErrorBanner
import ke.co.majilink.ui.components.LabeledField
import ke.co.majilink.ui.components.OutlineAction
import ke.co.majilink.ui.components.PrimaryButton
import ke.co.majilink.ui.components.SectionCard
import kotlinx.coroutines.launch

@Composable
fun UpdateScreen(gate: Gate.Update, onOpenServer: () -> Unit) {
    val context = LocalContext.current
    GateFrame {
        SectionCard {
            Text(
                if (gate.force) "Update required" else "Update available",
                fontSize = 20.sp,
                fontWeight = FontWeight.Bold
            )
            Spacer(Modifier.height(8.dp))
            Text(gate.message, fontSize = 15.sp, color = MaterialTheme.colorScheme.onSurface)
            Spacer(Modifier.height(12.dp))
            Text(
                "Latest version: ${gate.targetVersion.ifBlank { "unknown" }}  -  you are on " +
                    "${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})",
                fontSize = 13.sp,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
            if (gate.force) {
                Spacer(Modifier.height(12.dp))
                Text(
                    "Ordering stays locked until MajiLink is updated.",
                    fontSize = 13.sp,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
        }
        Spacer(Modifier.height(16.dp))
        if (gate.updateUrl.isNotBlank()) {
            PrimaryButton("Get the update", onClick = { openExternal(context, gate.updateUrl) })
            Spacer(Modifier.height(10.dp))
        }
        if (!gate.force) {
            OutlineAction("Not now", onClick = { AppGate.dismissOptionalUpdate() })
        }
        ServerLink(onOpenServer)
    }
}

@Composable
fun MaintenanceScreen(message: String, onOpenServer: () -> Unit) {
    val scope = rememberCoroutineScope()
    GateFrame {
        SectionCard {
            Text("Temporarily unavailable", fontSize = 20.sp, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(8.dp))
            Text(message, fontSize = 15.sp, color = MaterialTheme.colorScheme.onSurface)
        }
        Spacer(Modifier.height(16.dp))
        PrimaryButton("Check again", onClick = { scope.launch { ServerStore.refresh() } })
        ServerLink(onOpenServer)
    }
}

/**
 * Points this install at another backend without rebuilding. Saving drops the
 * session because the tokens belong to the server they were issued by.
 */
@Composable
fun ServerSettingsScreen(onClose: () -> Unit) {
    val scope = rememberCoroutineScope()
    var address by remember { mutableStateOf(ServerStore.effectiveBase) }
    var problem by remember { mutableStateOf<String?>(null) }
    var status by remember { mutableStateOf<String?>(null) }
    val config = ServerStore.config

    Scaffold { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(horizontal = 24.dp)
                .verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(14.dp, Alignment.CenterVertically)
        ) {
            Text("Server", fontSize = 24.sp, fontWeight = FontWeight.Bold)
            Text(
                "The app reads its version gate, maintenance state and limits from " +
                    "GET /api/v1/config on this address.",
                fontSize = 14.sp,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )

            ErrorBanner(problem)
            ErrorBanner(ServerStore.lastError)
            if (status != null) {
                SectionCard {
                    Text(status.orEmpty(), fontSize = 13.sp)
                }
            }

            LabeledField(
                label = "API base URL",
                value = address,
                onValueChange = { address = it; problem = null },
                keyboardType = KeyboardType.Uri,
                placeholder = "https://api.majilink.co.ke/api/v1/"
            )

            Row(horizontalArrangement = Arrangement.spacedBy(10.dp), modifier = Modifier.fillMaxWidth()) {
                PrimaryButton(
                    "Save",
                    onClick = {
                        val saved = ServerStore.setOverride(address)
                        if (saved == null) {
                            problem = "Not a valid http(s) address."
                        } else {
                            address = saved
                            problem = null
                            SessionStore.clear()
                            scope.launch { status = checked() }
                        }
                    },
                    modifier = Modifier.weight(1f)
                )
                OutlineAction(
                    "Reset",
                    enabled = ServerStore.isOverridden,
                    onClick = {
                        ServerStore.clearOverride()
                        address = ServerStore.effectiveBase
                        scope.launch { status = checked() }
                    },
                    modifier = Modifier.weight(1f)
                )
            }

            SectionCard {
                GateRow("In use", ServerStore.effectiveBase)
                GateRow(
                    "Source",
                    when {
                        ServerStore.isOverridden -> "manual override"
                        else -> "build default" + adoptedNote()
                    }
                )
                GateRow("Environment", config?.endpoint?.environment ?: "not fetched")
                GateRow("Backend version floor", (config?.app?.minSupportedVersionCode ?: 0).toString())
                GateRow("Recommended floor", (config?.app?.minRecommendedVersionCode ?: 0).toString())
                GateRow("Currency", config?.limits?.currency ?: "-")
                GateRow("Quantity", "${config?.limits?.minQuantity ?: "-"} to ${config?.limits?.maxQuantity ?: "-"}")
                GateRow("Flags", config?.flags?.keys?.sorted()?.joinToString().orEmpty().ifBlank { "none" })
            }

            OutlineAction("Close", onClick = { onClose() })
        }
    }
}

private suspend fun checked(): String {
    val fetched = ServerStore.refresh()
    return if (fetched == null) {
        "No config from ${ServerStore.effectiveBase}: ${ServerStore.lastError ?: "unreachable"}"
    } else {
        "Connected. Version ${fetched.app?.latestVersionName ?: "?"}, " +
            "environment ${fetched.endpoint?.environment ?: "?"}."
    }
}

private fun adoptedNote(): String =
    if (ServerStore.effectiveBase != BuildConfig.API_BASE) " (server-advertised)" else ""

@Composable
private fun GateFrame(content: @Composable () -> Unit) {
    Scaffold { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(horizontal = 24.dp)
                .verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.Center
        ) {
            content()
        }
    }
}

@Composable
private fun ServerLink(onOpenServer: () -> Unit) {
    if (!ServerStore.canOverrideBase) return
    Spacer(Modifier.height(12.dp))
    TextButton(onClick = onOpenServer) {
        Text("Change server", fontSize = 13.sp, color = MaterialTheme.colorScheme.primary)
    }
}

@Composable
private fun GateRow(label: String, value: String) {
    Row(Modifier.fillMaxWidth().padding(vertical = 3.dp)) {
        Text(label, fontSize = 13.sp, modifier = Modifier.weight(0.42f))
        Text(
            value,
            fontSize = 13.sp,
            fontWeight = FontWeight.SemiBold,
            modifier = Modifier.weight(0.58f)
        )
    }
}

private fun openExternal(context: Context, url: String) {
    try {
        context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
    } catch (ignored: ActivityNotFoundException) {
    }
}
