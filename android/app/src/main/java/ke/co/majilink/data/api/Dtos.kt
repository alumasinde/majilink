package ke.co.majilink.data.api

/**
 * Gson is configured with LOWER_CASE_WITH_UNDERSCORES, so the camelCase fields
 * below map directly onto the API's snake_case JSON.
 */

data class Envelope<T>(
    val data: T? = null,
    val meta: Meta? = null
)

data class Meta(
    val radiusKm: Double? = null,
    val maxRadiusKm: Double? = null,
    val count: Int? = null
)

data class ApiErrorEnvelope(val error: ApiError? = null)

data class ApiError(
    val code: String? = null,
    val message: String? = null,
    val fields: Map<String, String>? = null
)

class ApiException(
    val status: Int,
    val code: String?,
    override val message: String,
    val fields: Map<String, String> = emptyMap()
) : Exception(message)

// --- Auth -------------------------------------------------------------------

data class DeviceDto(
    val id: Long = 0,
    val deviceUid: String? = null,
    val pinSet: Boolean = false,
    val biometricEnabled: Boolean = false
)

data class UserDto(
    val id: Long = 0,
    val firstName: String = "",
    val lastName: String = "",
    val phone: String = "",
    val email: String? = null,
    val roles: List<String> = emptyList(),
    val permissions: List<String> = emptyList(),
    val modes: List<String> = emptyList(),
    val vendor: VendorRef? = null
)

data class VendorRef(
    val id: Long = 0,
    val businessName: String? = null,
    val status: String? = null
)

data class AuthPayload(
    val user: UserDto = UserDto(),
    val device: DeviceDto = DeviceDto(),
    val accessToken: String = "",
    val refreshToken: String = "",
    val tokenType: String = "Bearer",
    val expiresIn: Int = 900
)

data class OtpChallenge(
    val destination: String = "",
    val channel: String = "email",
    val expiresIn: Int = 600,
    val devCode: String? = null
)

data class OtpVerification(
    val verificationToken: String = "",
    val verified: Boolean = false,
    val destination: String = ""
)

data class SimpleMessage(val message: String? = null, val ok: Boolean? = null)

// --- Locations --------------------------------------------------------------

data class LocationDto(
    val id: Long = 0,
    val alias: String = "",
    val lat: Double = 0.0,
    val lng: Double = 0.0,
    val landmarkNote: String? = null,
    val isDefault: Boolean = false
)

// --- Vendors ----------------------------------------------------------------

data class WaterSourceDto(val id: Long = 0, val name: String = "")

data class VendorSummaryDto(
    val id: Long = 0,
    val businessName: String = "",
    val waterSource: WaterSourceDto = WaterSourceDto(),
    val pricePer20l: String = "0.00",
    val avgRating: Double = 0.0,
    val ratingCount: Int = 0,
    val distanceKm: Double = 0.0,
    val roadDistanceKm: Double? = null,
    val etaMinutes: Int? = null,
    val opensAt: String? = null,
    val closesAt: String? = null,
    val baseLat: Double = 0.0,
    val baseLng: Double = 0.0
)

data class NearbyVendorsPayload(val vendors: List<VendorSummaryDto> = emptyList())

data class VendorDetailDto(
    val id: Long = 0,
    val businessName: String = "",
    val waterSource: String = "",
    val pricePer20l: String = "0.00",
    val avgRating: Double = 0.0,
    val ratingCount: Int = 0,
    val opensAt: String? = null,
    val closesAt: String? = null,
    val isOnline: Boolean = false,
    val baseLat: Double = 0.0,
    val baseLng: Double = 0.0,
    val baseNote: String? = null
)

// --- Orders -----------------------------------------------------------------

data class StatusDto(val name: String = "", val isFinal: Boolean = false)

data class PaymentDto(
    val method: String? = null,
    val status: String? = null,
    val receipt: String? = null
)

data class PartyDto(
    val id: Long? = null,
    val firstName: String = "",
    val lastName: String? = null,
    val phone: String? = null
)

data class VendorPartyDto(
    val id: Long = 0,
    val businessName: String = "",
    val phone: String? = null,
    val baseLat: Double = 0.0,
    val baseLng: Double = 0.0
)

data class DeliveryDto(
    val alias: String? = null,
    val lat: Double? = null,
    val lng: Double? = null,
    val landmark: String? = null
)

data class RatingDto(val stars: Int = 0, val comment: String? = null)

data class OrderActionsDto(
    val cancel: Boolean = false,
    val rate: Boolean = false,
    val callVendor: Boolean = false,
    val payMpesa: Boolean = false,
    val accept: Boolean = false,
    val decline: Boolean = false,
    val onTheWay: Boolean = false,
    val delivered: Boolean = false,
    val navigate: Boolean = false,
    val callCustomer: Boolean = false
)

data class HistoryStepDto(
    val id: Long = 0,
    val status: String = "",
    val note: String? = null,
    val changedBy: String? = null,
    val createdAt: String = ""
)

data class OrderDto(
    val id: Long = 0,
    val orderNo: String = "",
    val status: StatusDto = StatusDto(),
    val quantity: Int = 0,
    val unitPrice: String = "0.00",
    val deliveryFee: String = "0.00",
    val total: String = "0.00",
    val requestedAt: String? = null,
    val acceptedAt: String? = null,
    val deliveredAt: String? = null,
    val expiresAt: String? = null,
    val expiresInSeconds: Int? = null,
    val payment: PaymentDto = PaymentDto(),
    val distanceKm: Double = 0.0,
    val rating: RatingDto? = null,
    val customer: PartyDto = PartyDto(),
    val vendor: VendorPartyDto = VendorPartyDto(),
    val delivery: DeliveryDto = DeliveryDto(),
    val note: String? = null,
    val actions: OrderActionsDto = OrderActionsDto(),
    val history: List<HistoryStepDto> = emptyList()
)

data class OrdersPayload(val orders: List<OrderDto> = emptyList())

data class QuoteDto(
    val quantity: Int = 0,
    val unitPrice: String = "0.00",
    val subtotal: String = "0.00",
    val deliveryFee: String = "0.00",
    val total: String = "0.00",
    val distanceKm: Double = 0.0
)

data class CreateOrderRequest(
    val vendorId: Long,
    val locationId: Long,
    val quantity: Int,
    val note: String? = null,
    val paymentMethod: String = "Cash"
)

data class StkPushDto(
    val paymentId: Long = 0,
    val checkoutRequestId: String = "",
    val message: String = "",
    val simulated: Boolean = false,
    val amount: String = "0.00"
)

// --- Recurring --------------------------------------------------------------

data class RecurringDto(
    val id: Long = 0,
    val vendorId: Long = 0,
    val businessName: String = "",
    val frequency: String = "",
    val frequencyId: Long = 0,
    val quantity: Int = 0,
    val nextRunAt: String = "",
    val isActive: Boolean = true,
    val locationAlias: String? = null,
    val estimatedTotal: String? = null
)

data class RecurringListPayload(val schedules: List<RecurringDto> = emptyList())

// --- Wallet -----------------------------------------------------------------

data class WalletDto(
    val balance: String = "0.00",
    val pendingPayouts: String = "0.00",
    val available: String = "0.00",
    val earnedToday: String = "0.00",
    val earnedWeek: String = "0.00"
)

data class WalletTransactionDto(
    val id: Long = 0,
    val type: String = "",
    val amount: String = "0.00",
    val balanceAfter: String = "0.00",
    val note: String? = null,
    val createdAt: String = ""
)

data class TransactionsPayload(val transactions: List<WalletTransactionDto> = emptyList())

data class PayoutDto(
    val id: Long = 0,
    val amount: String = "0.00",
    val status: String = "",
    val mpesaReceipt: String? = null,
    val requestedAt: String = "",
    val processedAt: String? = null
)

data class PayoutsPayload(val payouts: List<PayoutDto> = emptyList())

// --- Notifications ----------------------------------------------------------

data class NotificationDto(
    val id: Long = 0,
    val title: String = "",
    val body: String = "",
    val readAt: String? = null,
    val createdAt: String = ""
)

data class NotificationsPayload(val notifications: List<NotificationDto> = emptyList())

// --- Vendor application -----------------------------------------------------

data class VendorApplicationRequest(
    val businessName: String,
    val waterSourceId: Long,
    val pricePer20l: Double,
    val deliveryRadiusKm: Double,
    val baseLat: Double,
    val baseLng: Double,
    val baseNote: String? = null,
    val opensAt: String? = null,
    val closesAt: String? = null,
    val payoutPhone: String
)

data class VendorDocumentDto(
    val id: Long = 0,
    val documentTypeId: Long = 0,
    val documentType: String? = null,
    val uploadedAt: String? = null
)

data class VendorApplicationDto(
    val id: Long = 0,
    val businessName: String = "",
    val waterSource: String = "",
    val pricePer20l: String = "0.00",
    val deliveryRadiusKm: String = "0.00",
    val baseLat: Double = 0.0,
    val baseLng: Double = 0.0,
    val status: String = "",
    val rejectionReason: String? = null,
    val isOnline: Boolean = false,
    val documents: List<VendorDocumentDto> = emptyList()
)

data class ApplicationPayload(val application: VendorApplicationDto? = null)
