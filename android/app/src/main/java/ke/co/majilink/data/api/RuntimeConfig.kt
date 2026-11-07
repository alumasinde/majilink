package ke.co.majilink.data.api

/**
 * Mirrors GET /api/v1/config. Field names follow the API's snake_case through the
 * shared Gson naming policy.
 */
data class RuntimeConfig(
    val app: AppGateConfig? = null,
    val maintenance: MaintenanceConfig? = null,
    val endpoint: EndpointConfig? = null,
    val flags: Map<String, Boolean> = emptyMap(),
    val limits: RuntimeLimits? = null,
    val serverTime: String? = null
)

data class AppGateConfig(
    val minSupportedVersionCode: Int = 0,
    val minRecommendedVersionCode: Int = 0,
    val latestVersionName: String = "",
    val updateUrl: String = "",
    val updateMessage: String = "",
    val forceUpdate: Boolean = false,
    val allowEndpointOverride: Boolean = false
)

data class MaintenanceConfig(
    val enabled: Boolean = false,
    val message: String = ""
)

data class EndpointConfig(
    val apiBaseUrl: String = "",
    val environment: String = ""
)

data class RuntimeLimits(
    val currency: String = "KES",
    val minQuantity: Int = 1,
    val maxQuantity: Int = 20,
    val defaultRadiusKm: Int = 3,
    val maxRadiusKm: Int = 5,
    val orderExpiryMinutes: Int = 5
)
