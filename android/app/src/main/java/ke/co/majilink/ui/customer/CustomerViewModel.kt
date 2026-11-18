package ke.co.majilink.ui.customer

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import ke.co.majilink.data.MajiRepository
import ke.co.majilink.data.SessionStore
import ke.co.majilink.data.api.ApiException
import ke.co.majilink.data.api.LocationDto
import ke.co.majilink.data.api.OrderDto
import ke.co.majilink.data.api.QuoteDto
import ke.co.majilink.data.api.RecurringDto
import ke.co.majilink.data.api.VendorSummaryDto
import ke.co.majilink.data.local.MajiDatabase
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch

data class HomeState(
    val loading: Boolean = true,
    val vendors: List<VendorSummaryDto> = emptyList(),
    val activeOrder: OrderDto? = null,
    val error: String? = null,
    val offline: Boolean = false,
    val radiusKm: Double = 3.0,
    val maxRadiusKm: Double = 5.0
)

class CustomerViewModel(app: Application) : AndroidViewModel(app) {

    private val db = MajiDatabase.get(app)
    private val repo = MajiRepository(dao = db.dao())

    val cachedVendors = db.dao().vendors()
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), emptyList())
    val cachedOrders = db.dao().orders()
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), emptyList())

    var home by mutableStateOf(HomeState())
        private set
    var locations by mutableStateOf<List<LocationDto>>(emptyList())
        private set
    var selectedLocation by mutableStateOf<LocationDto?>(null)
        private set
    var orders by mutableStateOf<List<OrderDto>>(emptyList())
        private set
    var ordersLoading by mutableStateOf(false)
        private set
    var schedules by mutableStateOf<List<RecurringDto>>(emptyList())
        private set
    var schedulesLoading by mutableStateOf(false)
        private set
    var tracking by mutableStateOf<OrderDto?>(null)
        private set
    var trackingLoading by mutableStateOf(false)
        private set
    var quote by mutableStateOf<QuoteDto?>(null)
        private set
    var vendorDetail by mutableStateOf<ke.co.majilink.data.api.VendorDetailDto?>(null)
        private set
    var devices by mutableStateOf<List<ke.co.majilink.data.api.DeviceDto>>(emptyList())
        private set
    var busy by mutableStateOf(false)
        private set
    var message by mutableStateOf<String?>(null)
        private set

    init {
        loadLocations()
    }

    fun clearMessage() {
        message = null
    }

    fun loadLocations(selectDefault: Boolean = true) {
        viewModelScope.launch {
            repo.locations().fold(
                onSuccess = { payload ->
                    locations = payload["locations"].orEmpty()
                    if (selectDefault || selectedLocation == null) {
                        selectedLocation = locations.firstOrNull { it.isDefault } ?: locations.firstOrNull()
                    }
                    selectedLocation?.let { loadVendors(it.id) }
                },
                onFailure = { fail(it) }
            )
        }
    }

    fun selectLocation(id: Long) {
        selectedLocation = locations.firstOrNull { it.id == id }
        home = home.copy(loading = true)
        loadVendors(id)
    }

    fun loadVendors(locationId: Long, radiusKm: Double? = null) {
        home = home.copy(loading = true)
        viewModelScope.launch {
            repo.nearbyVendors(locationId, radiusKm).fold(
                onSuccess = { payload ->
                    home = home.copy(
                        loading = false,
                        vendors = payload.vendors,
                        error = null,
                        offline = false
                    )
                },
                onFailure = {
                    home = home.copy(loading = false, offline = true)
                    fail(it)
                }
            )
        }
        loadActiveOrder()
    }

    fun widenRadius() {
        val location = selectedLocation ?: return
        val next = (home.radiusKm + 2.0).coerceAtMost(home.maxRadiusKm)
        home = home.copy(radiusKm = next)
        loadVendors(location.id, next)
    }

    fun setRadius(km: Double) {
        val location = selectedLocation ?: return
        val clamped = km.coerceIn(0.5, home.maxRadiusKm)
        home = home.copy(radiusKm = clamped)
        loadVendors(location.id, clamped)
    }

    fun loadActiveOrder() {
        viewModelScope.launch {
            repo.orders("active").fold(
                onSuccess = { home = home.copy(activeOrder = it.orders.firstOrNull()) },
                onFailure = { /* keep the cached view */ }
            )
        }
    }

    fun loadOrders() {
        viewModelScope.launch {
            ordersLoading = true
            repo.orders().fold(
                onSuccess = { orders = it.orders },
                onFailure = { fail(it) }
            )
            ordersLoading = false
        }
    }

    fun loadOrder(id: Long) {
        viewModelScope.launch {
            trackingLoading = true
            repo.order(id).fold(
                onSuccess = { tracking = it },
                onFailure = { fail(it) }
            )
            trackingLoading = false
        }
    }

    fun requestQuote(vendorId: Long, locationId: Long, quantity: Int) {
        viewModelScope.launch {
            repo.quote(vendorId, locationId, quantity).fold(
                onSuccess = { quote = it },
                onFailure = { fail(it) }
            )
        }
    }

    fun placeOrder(
        vendorId: Long,
        locationId: Long,
        quantity: Int,
        note: String,
        paymentMethod: String,
        onPlaced: (OrderDto) -> Unit
    ) {
        viewModelScope.launch {
            busy = true
            repo.createOrder(vendorId, locationId, quantity, note.ifBlank { null }, paymentMethod).fold(
                onSuccess = {
                    busy = false
                    home = home.copy(activeOrder = it)
                    onPlaced(it)
                },
                onFailure = {
                    busy = false
                    fail(it)
                }
            )
        }
    }

    fun cancelOrder(id: Long, reason: String?, onDone: () -> Unit = {}) {
        viewModelScope.launch {
            repo.cancelOrder(id, reason).fold(
                onSuccess = {
                    tracking = it
                    message = "Order cancelled."
                    onDone()
                },
                onFailure = { fail(it) }
            )
        }
    }

    fun rateOrder(id: Long, stars: Int, comment: String) {
        viewModelScope.launch {
            repo.rateOrder(id, stars, comment.ifBlank { null }).fold(
                onSuccess = {
                    message = "Thanks for rating."
                    loadOrder(id)
                },
                onFailure = { fail(it) }
            )
        }
    }

    fun payWithMpesa(id: Long, phone: String?) {
        viewModelScope.launch {
            repo.payWithMpesa(id, phone).fold(
                onSuccess = { message = it.message.ifBlank { "Check your phone for the M-Pesa prompt." } },
                onFailure = { fail(it) }
            )
        }
    }

    fun addLocation(alias: String, lat: Double, lng: Double, note: String) {
        viewModelScope.launch {
            busy = true
            repo.createLocation(alias, lat, lng, note.ifBlank { null }, locations.isEmpty()).fold(
                onSuccess = {
                    busy = false
                    message = "Home pin saved."
                    loadLocations()
                },
                onFailure = {
                    busy = false
                    fail(it)
                }
            )
        }
    }

    fun removeLocation(id: Long) {
        viewModelScope.launch {
            repo.deleteLocation(id).fold(
                onSuccess = {
                    message = "Pin removed."
                    loadLocations()
                },
                onFailure = { fail(it) }
            )
        }
    }

    fun loadVendor(id: Long) {
        viewModelScope.launch {
            repo.vendor(id).fold(
                onSuccess = { vendorDetail = it },
                onFailure = { fail(it) }
            )
        }
    }

    fun loadDevices() {
        viewModelScope.launch {
            repo.devices().fold(
                onSuccess = { devices = it["devices"].orEmpty() },
                onFailure = { /* silent: device list is secondary */ }
            )
        }
    }

    fun loadSchedules() {
        viewModelScope.launch {
            schedulesLoading = true
            repo.recurringOrders().fold(
                onSuccess = { schedules = it.schedules },
                onFailure = { fail(it) }
            )
            schedulesLoading = false
        }
    }

    fun createSchedule(vendorId: Long, locationId: Long, frequencyId: Long, quantity: Int) {
        viewModelScope.launch {
            repo.createRecurring(vendorId, locationId, frequencyId, quantity).fold(
                onSuccess = {
                    message = "Repeat delivery scheduled."
                    loadSchedules()
                },
                onFailure = { fail(it) }
            )
        }
    }

    fun deleteSchedule(id: Long) {
        viewModelScope.launch {
            repo.deleteRecurring(id).fold(
                onSuccess = {
                    message = "Schedule removed."
                    loadSchedules()
                },
                onFailure = { fail(it) }
            )
        }
    }

    fun setBiometricFlag(enabled: Boolean) {
        viewModelScope.launch { repo.setBiometric(enabled) }
    }

    fun logout(onDone: () -> Unit) {
        viewModelScope.launch {
            runCatching { repo.logout() }
            SessionStore.clear()
            onDone()
        }
    }

    private fun fail(error: Throwable) {
        message = (error as? ApiException)?.message ?: error.message ?: "Something went wrong."
    }
}
