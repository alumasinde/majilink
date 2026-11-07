package ke.co.majilink.data

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import ke.co.majilink.BuildConfig

sealed interface Gate {
    data object Ready : Gate
    data class Maintenance(val message: String) : Gate
    data class Update(
        val message: String,
        val updateUrl: String,
        val targetVersion: String,
        val force: Boolean
    ) : Gate
}

/**
 * Server-driven wall in front of the app, fed by GET /api/v1/config. A cached config
 * is honoured offline; a config that has never been fetched blocks nothing, because
 * an unreachable server is not a reason to lock the user out of a working app.
 */
object AppGate {

    var state: Gate by mutableStateOf(Gate.Ready)
        private set

    private var dismissedBelowCode = 0

    fun evaluate() {
        val config = ServerStore.config
        if (config == null) {
            state = Gate.Ready
            return
        }

        val maintenance = config.maintenance
        if (maintenance?.enabled == true) {
            state = Gate.Maintenance(
                maintenance.message.ifBlank { "MajiLink is taking a short break. Try again shortly." }
            )
            return
        }

        val app = config.app
        val code = BuildConfig.VERSION_CODE
        val floor = app?.minSupportedVersionCode ?: 0
        val recommended = app?.minRecommendedVersionCode ?: 0

        when {
            app != null && code < floor -> state = Gate.Update(
                message = app.updateMessage.ifBlank { DEFAULT_MESSAGE },
                updateUrl = app.updateUrl,
                targetVersion = app.latestVersionName,
                force = true
            )
            app != null && code < recommended && dismissedBelowCode != recommended -> state = Gate.Update(
                message = app.updateMessage.ifBlank { DEFAULT_MESSAGE },
                updateUrl = app.updateUrl,
                targetVersion = app.latestVersionName,
                force = app.forceUpdate
            )
            else -> state = Gate.Ready
        }
    }

    fun dismissOptionalUpdate() {
        val gate = state as? Gate.Update ?: return
        if (gate.force) return
        dismissedBelowCode = ServerStore.config?.app?.minRecommendedVersionCode ?: dismissedBelowCode
        state = Gate.Ready
    }

    private const val DEFAULT_MESSAGE = "A newer version of MajiLink is available. Update to keep ordering."
}
