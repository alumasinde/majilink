package ke.co.majilink.data.api

import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.Path
import retrofit2.http.Query

interface MajiApi {

    // Auth
    @POST("auth/otp/request")
    suspend fun requestOtp(@Body body: Map<String, String>): Envelope<OtpChallenge>

    @POST("auth/otp/verify")
    suspend fun verifyOtp(@Body body: Map<String, String>): Envelope<OtpVerification>

    @POST("auth/register")
    suspend fun register(@Body body: RegisterRequest): Envelope<AuthPayload>

    @POST("auth/login")
    suspend fun login(@Body body: PasswordLoginRequest): Envelope<AuthPayload>

    @POST("auth/login/pin")
    suspend fun loginWithPin(@Body body: PinLoginRequest): Envelope<AuthPayload>

    @POST("auth/refresh")
    suspend fun refresh(@Body body: Map<String, String>): Envelope<AuthPayload>

    @GET("auth/me")
    suspend fun me(): Envelope<UserDto>

    @POST("auth/pin")
    suspend fun setPin(@Body body: Map<String, String>): Envelope<Map<String, Any>>

    @POST("auth/biometric")
    suspend fun setBiometric(@Body body: Map<String, Boolean>): Envelope<Map<String, Boolean>>

    @POST("auth/push-token")
    suspend fun savePushToken(@Body body: Map<String, String?>): Envelope<Map<String, Boolean>>

    @POST("auth/logout")
    suspend fun logout(): Envelope<SimpleMessage>

    @GET("auth/devices")
    suspend fun devices(): Envelope<Map<String, List<DeviceDto>>>

    // Customer: pins
    @GET("locations")
    suspend fun locations(): Envelope<Map<String, List<LocationDto>>>

    @POST("locations")
    suspend fun createLocation(@Body body: Map<String, Any?>): Envelope<LocationDto>

    @PUT("locations/{id}")
    suspend fun updateLocation(@Path("id") id: Long, @Body body: Map<String, Any?>): Envelope<LocationDto>

    @DELETE("locations/{id}")
    suspend fun deleteLocation(@Path("id") id: Long): Envelope<Map<String, Boolean>>

    // Customer: vendors
    @GET("vendors/nearby")
    suspend fun nearbyVendors(
        @Query("location_id") locationId: Long,
        @Query("radius_km") radiusKm: Double? = null
    ): Envelope<NearbyVendorsPayload>

    @GET("vendors/{id}")
    suspend fun vendor(@Path("id") id: Long): Envelope<VendorDetailDto>

    // Customer: orders
    @POST("orders/quote")
    suspend fun quote(@Body body: Map<String, Long>): Envelope<QuoteDto>

    @POST("orders")
    suspend fun createOrder(@Body body: CreateOrderRequest): Envelope<OrderDto>

    @GET("orders")
    suspend fun orders(@Query("filter") filter: String? = null): Envelope<OrdersPayload>

    @GET("orders/{id}")
    suspend fun order(@Path("id") id: Long): Envelope<OrderDto>

    @POST("orders/{id}/cancel")
    suspend fun cancelOrder(@Path("id") id: Long, @Body body: Map<String, String>): Envelope<OrderDto>

    @POST("orders/{id}/rate")
    suspend fun rateOrder(@Path("id") id: Long, @Body body: Map<String, Any>): Envelope<Map<String, Any>>

    @POST("orders/{id}/pay/mpesa")
    suspend fun payWithMpesa(@Path("id") id: Long, @Body body: Map<String, String>): Envelope<StkPushDto>

    // Customer: schedules and notifications
    @GET("recurring-orders")
    suspend fun recurringOrders(): Envelope<RecurringListPayload>

    @POST("recurring-orders")
    suspend fun createRecurring(@Body body: Map<String, Any>): Envelope<RecurringDto>

    @PUT("recurring-orders/{id}")
    suspend fun updateRecurring(@Path("id") id: Long, @Body body: Map<String, Any>): Envelope<RecurringDto>

    @DELETE("recurring-orders/{id}")
    suspend fun deleteRecurring(@Path("id") id: Long): Envelope<Map<String, Boolean>>

    @GET("notifications")
    suspend fun notifications(): Envelope<NotificationsPayload>

    @POST("notifications/{id}/read")
    suspend fun readNotification(@Path("id") id: Long): Envelope<Map<String, Boolean>>

    // Vendor
    @POST("vendor/apply")
    suspend fun applyAsVendor(@Body body: VendorApplicationRequest): Envelope<VendorApplicationDto>

    @GET("vendor/application")
    suspend fun vendorApplication(): Envelope<ApplicationPayload>

    @Multipart
    @POST("vendor/documents")
    suspend fun uploadDocument(
        @Part("document_type_id") documentTypeId: RequestBody,
        @Part file: MultipartBody.Part
    ): Envelope<VendorDocumentDto>

    @GET("vendor/profile")
    suspend fun vendorProfile(): Envelope<ApplicationPayload>

    @PUT("vendor/profile")
    suspend fun updateVendorProfile(@Body body: Map<String, Any?>): Envelope<VendorApplicationDto>

    @POST("vendor/online")
    suspend fun setOnline(@Body body: Map<String, Boolean>): Envelope<Map<String, Any>>

    @POST("vendor/heartbeat")
    suspend fun heartbeat(): Envelope<Map<String, Any>>

    @GET("vendor/orders")
    suspend fun vendorOrders(@Query("filter") filter: String? = null): Envelope<OrdersPayload>

    @GET("vendor/orders/{id}")
    suspend fun vendorOrder(@Path("id") id: Long): Envelope<OrderDto>

    @POST("vendor/orders/{id}/accept")
    suspend fun acceptOrder(@Path("id") id: Long): Envelope<OrderDto>

    @POST("vendor/orders/{id}/decline")
    suspend fun declineOrder(@Path("id") id: Long, @Body body: Map<String, String>): Envelope<OrderDto>

    @POST("vendor/orders/{id}/on-the-way")
    suspend fun markOnTheWay(@Path("id") id: Long): Envelope<OrderDto>

    @POST("vendor/orders/{id}/delivered")
    suspend fun markDelivered(@Path("id") id: Long): Envelope<OrderDto>

    @GET("vendor/wallet")
    suspend fun wallet(): Envelope<WalletDto>

    @GET("vendor/transactions")
    suspend fun transactions(): Envelope<TransactionsPayload>

    @GET("vendor/payouts")
    suspend fun payouts(): Envelope<PayoutsPayload>

    @POST("vendor/payouts")
    suspend fun requestPayout(@Body body: Map<String, String>): Envelope<PayoutDto>

    @GET("vendor/ratings")
    suspend fun vendorRatings(): Envelope<Map<String, List<Map<String, Any>>>>
}

data class RegisterRequest(
    val verificationToken: String,
    val firstName: String,
    val lastName: String,
    val phone: String,
    val email: String?,
    val password: String,
    val device: DeviceRequest
)

data class DeviceRequest(
    val deviceUid: String,
    val deviceName: String,
    val pin: String? = null,
    val biometricEnabled: Boolean = false
)

data class PasswordLoginRequest(
    val identifier: String,
    val password: String,
    val device: DeviceRequest
)

data class PinLoginRequest(
    val identifier: String,
    val deviceUid: String,
    val pin: String
)
