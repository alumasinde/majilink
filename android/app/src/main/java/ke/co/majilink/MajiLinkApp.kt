package ke.co.majilink

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.media.AudioAttributes
import android.media.RingtoneManager
import android.os.Build
import ke.co.majilink.data.AppGate
import ke.co.majilink.data.ServerStore
import ke.co.majilink.data.SessionStore
import ke.co.majilink.data.api.ApiClient
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import org.maplibre.android.MapLibre

class MajiLinkApp : Application() {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Default)

    override fun onCreate() {
        super.onCreate()
        MapLibre.getInstance(this)
        SessionStore.init(this)
        ServerStore.init(this)
        ApiClient.init(ServerStore.effectiveBase)
        AppGate.evaluate()
        createNotificationChannels()
        scope.launch { ServerStore.refresh() }
    }

    private fun createNotificationChannels() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = getSystemService(NotificationManager::class.java)

        // New orders use a loud, high-importance channel so vendors hear them.
        val orders = NotificationChannel(
            CHANNEL_ORDERS,
            "New orders",
            NotificationManager.IMPORTANCE_HIGH
        ).apply {
            description = "Incoming water orders and delivery updates"
            enableVibration(true)
            val sound = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION)
            setSound(
                sound,
                AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_NOTIFICATION)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                    .build()
            )
        }

        val general = NotificationChannel(
            CHANNEL_DEFAULT,
            "MajiLink updates",
            NotificationManager.IMPORTANCE_DEFAULT
        ).apply { description = "Wallet, payout and account updates" }

        manager.createNotificationChannels(listOf(orders, general))
    }

    companion object {
        const val CHANNEL_ORDERS = "majilink_orders"
        const val CHANNEL_DEFAULT = "majilink_default"
    }
}
