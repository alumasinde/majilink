package ke.co.majilink.data

import ke.co.majilink.data.api.ApiClient
import ke.co.majilink.data.api.DeviceRequest
import ke.co.majilink.data.api.MajiApi
import ke.co.majilink.data.api.PasswordLoginRequest
import ke.co.majilink.data.api.PinLoginRequest
import ke.co.majilink.data.api.RegisterRequest
import ke.co.majilink.data.local.CachedLocation
import ke.co.majilink.data.local.CachedOrder
import ke.co.majilink.data.local.CachedVendor
import ke.co.majilink.data.local.MajiDao
import ke.co.majilink.data.api.CreateOrderRequest
import ke.co.majilink.data.api.VendorApplicationRequest

/**
 * Single gateway to the API. Every call returns a Result so the UI can show the
 * server's message without a try/catch at each call site.
 */
class MajiRepository(
    private val dao: MajiDao? = null
) {

    // Resolved per call: changing the server rebuilds the Retrofit instance held by ApiClient.
    private val api: MajiApi
        get() = ApiClient.api

    // --- Auth ---------------------------------------------------------------

    suspend fun requestOtp(destination: String, purpose: String) =
        ApiClient.safe { api.requestOtp(mapOf("destination" to destination, "purpose" to purpose)) }

    suspend fun verifyOtp(destination: String, purpose: String, code: String) =
        ApiClient.safe {
            api.verifyOtp(mapOf("destination" to destination, "purpose" to purpose, "code" to code))
        }

    suspend fun register(
        verificationToken: String,
        firstName: String,
        lastName: String,
        phone: String,
        email: String?,
        password: String,
        pin: String
    ) = ApiClient.safe {
        api.register(
            RegisterRequest(
                verificationToken = verificationToken,
                firstName = firstName,
                lastName = lastName,
                phone = phone,
                email = email,
                password = password,
                device = DeviceRequest(SessionStore.deviceUid, SessionStore.deviceName, pin, false)
            )
        )
    }

    suspend fun login(identifier: String, password: String) = ApiClient.safe {
        api.login(
            PasswordLoginRequest(
                identifier = identifier,
                password = password,
                device = DeviceRequest(SessionStore.deviceUid, SessionStore.deviceName, null, false)
            )
        )
    }

    suspend fun loginWithPin(identifier: String, pin: String) = ApiClient.safe {
        api.loginWithPin(PinLoginRequest(identifier, SessionStore.deviceUid, pin))
    }

    suspend fun me() = ApiClient.safe { api.me() }

    /** Used after a successful BiometricPrompt unlock on a known device. */
    suspend fun refreshSession() = ApiClient.safe {
        api.refresh(
            mapOf(
                "refresh_token" to (SessionStore.refreshToken ?: ""),
                "device_uid" to SessionStore.deviceUid
            )
        )
    }

    suspend fun setPin(pin: String, currentPassword: String?) = ApiClient.safe {
        val body = mutableMapOf("pin" to pin)
        if (!currentPassword.isNullOrBlank()) body["current_password"] = currentPassword
        api.setPin(body)
    }

    suspend fun setBiometric(enabled: Boolean) = ApiClient.safe { api.setBiometric(mapOf("enabled" to enabled)) }

    suspend fun savePushToken(token: String) = ApiClient.safe { api.savePushToken(mapOf("fcm_token" to token)) }

    suspend fun logout() = ApiClient.safe { api.logout() }

    suspend fun devices() = ApiClient.safe { api.devices() }

    // --- Locations ----------------------------------------------------------

    suspend fun locations() = ApiClient.safe { api.locations() }.onSuccess { payload ->
        dao?.replaceLocations(
            payload["locations"].orEmpty().map {
                CachedLocation(it.id, it.alias, it.lat, it.lng, it.isDefault)
            }
        )
    }

    suspend fun createLocation(alias: String, lat: Double, lng: Double, note: String?, isDefault: Boolean) =
        ApiClient.safe {
            api.createLocation(
                mapOf(
                    "alias" to alias,
                    "lat" to lat,
                    "lng" to lng,
                    "landmark_note" to note,
                    "is_default" to isDefault
                )
            )
        }

    suspend fun deleteLocation(id: Long) = ApiClient.safe { api.deleteLocation(id) }

    // --- Vendors ------------------------------------------------------------

    suspend fun nearbyVendors(locationId: Long, radiusKm: Double? = null) =
        ApiClient.safe { api.nearbyVendors(locationId, radiusKm) }.onSuccess { payload ->
            dao?.replaceVendors(
                payload.vendors.map {
                    CachedVendor(
                        id = it.id,
                        businessName = it.businessName,
                        waterSource = it.waterSource.name,
                        pricePer20l = it.pricePer20l,
                        avgRating = it.avgRating,
                        distanceKm = it.distanceKm,
                        etaMinutes = it.etaMinutes,
                        lat = it.baseLat,
                        lng = it.baseLng
                    )
                }
            )
        }

    suspend fun vendor(id: Long) = ApiClient.safe { api.vendor(id) }

    // --- Orders -------------------------------------------------------------

    suspend fun quote(vendorId: Long, locationId: Long, quantity: Int) = ApiClient.safe {
        api.quote(mapOf("vendor_id" to vendorId, "location_id" to locationId, "quantity" to quantity.toLong()))
    }

    suspend fun createOrder(vendorId: Long, locationId: Long, quantity: Int, note: String?, method: String) =
        ApiClient.safe {
            api.createOrder(CreateOrderRequest(vendorId, locationId, quantity, note, method))
        }

    suspend fun orders(filter: String? = null) =
        ApiClient.safe { api.orders(filter) }.onSuccess { payload ->
            dao?.replaceOrders(
                payload.orders.map {
                    CachedOrder(
                        id = it.id,
                        orderNo = it.orderNo,
                        status = it.status.name,
                        total = it.total,
                        alias = it.delivery.alias,
                        createdAt = it.requestedAt.orEmpty(),
                        vendorName = it.vendor.businessName
                    )
                }
            )
        }

    suspend fun order(id: Long) = ApiClient.safe { api.order(id) }

    suspend fun cancelOrder(id: Long, reason: String?) = ApiClient.safe {
        api.cancelOrder(id, if (reason.isNullOrBlank()) emptyMap() else mapOf("reason" to reason))
    }

    suspend fun rateOrder(id: Long, stars: Int, comment: String?) = ApiClient.safe {
        val body = mutableMapOf<String, Any>("stars" to stars)
        if (!comment.isNullOrBlank()) body["comment"] = comment
        api.rateOrder(id, body)
    }

    suspend fun payWithMpesa(id: Long, phone: String?) = ApiClient.safe {
        api.payWithMpesa(id, if (phone.isNullOrBlank()) emptyMap() else mapOf("phone" to phone))
    }

    suspend fun recurringOrders() = ApiClient.safe { api.recurringOrders() }

    suspend fun createRecurring(vendorId: Long, locationId: Long, frequencyId: Long, quantity: Int) =
        ApiClient.safe {
            api.createRecurring(
                mapOf(
                    "vendor_id" to vendorId,
                    "location_id" to locationId,
                    "frequency_id" to frequencyId,
                    "quantity" to quantity
                )
            )
        }

    suspend fun deleteRecurring(id: Long) = ApiClient.safe { api.deleteRecurring(id) }

    suspend fun notifications() = ApiClient.safe { api.notifications() }

    // --- Vendor -------------------------------------------------------------

    suspend fun applyAsVendor(request: VendorApplicationRequest) =
        ApiClient.safe { api.applyAsVendor(request) }

    suspend fun vendorApplication() = ApiClient.safe { api.vendorApplication() }

    suspend fun updateVendorProfile(body: Map<String, Any?>) =
        ApiClient.safe { api.updateVendorProfile(body) }

    suspend fun setOnline(online: Boolean) = ApiClient.safe { api.setOnline(mapOf("is_online" to online)) }

    suspend fun heartbeat() = ApiClient.safe { api.heartbeat() }

    suspend fun vendorOrders(filter: String? = null) = ApiClient.safe { api.vendorOrders(filter) }

    suspend fun vendorOrder(id: Long) = ApiClient.safe { api.vendorOrder(id) }

    suspend fun acceptOrder(id: Long) = ApiClient.safe { api.acceptOrder(id) }

    suspend fun declineOrder(id: Long, reason: String?) = ApiClient.safe {
        api.declineOrder(id, if (reason.isNullOrBlank()) emptyMap() else mapOf("reason" to reason))
    }

    suspend fun markOnTheWay(id: Long) = ApiClient.safe { api.markOnTheWay(id) }

    suspend fun markDelivered(id: Long) = ApiClient.safe { api.markDelivered(id) }

    suspend fun wallet() = ApiClient.safe { api.wallet() }

    suspend fun transactions() = ApiClient.safe { api.transactions() }

    suspend fun payouts() = ApiClient.safe { api.payouts() }

    suspend fun requestPayout(amount: String) = ApiClient.safe { api.requestPayout(mapOf("amount" to amount)) }
}
