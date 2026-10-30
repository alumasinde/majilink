package ke.co.majilink.ui.vendor

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import ke.co.majilink.data.MajiRepository
import ke.co.majilink.data.SessionStore
import ke.co.majilink.data.api.ApiException
import ke.co.majilink.data.api.OrderDto
import ke.co.majilink.data.api.PayoutDto
import ke.co.majilink.data.api.VendorApplicationDto
import ke.co.majilink.data.api.VendorApplicationRequest
import ke.co.majilink.data.api.WalletDto
import ke.co.majilink.data.api.WalletTransactionDto
import ke.co.majilink.data.local.MajiDatabase
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

data class VendorState(
    val loading: Boolean = true,
    val online: Boolean = false,
    val orders: List<OrderDto> = emptyList(),
    val application: VendorApplicationDto? = null,
    val wallet: WalletDto? = null,
    val transactions: List<WalletTransactionDto> = emptyList(),
    val payouts: List<PayoutDto> = emptyList(),
    val message: String? = null,
    val busy: Boolean = false
)

class VendorViewModel(app: Application) : AndroidViewModel(app) {

    private val db = MajiDatabase.get(app)
    private val repo = MajiRepository(dao = db.dao())
    private var pollJob: Job? = null

    var state by mutableStateOf(VendorState())
        private set

    var selectedOrder by mutableStateOf<OrderDto?>(null)
        private set

    init {
        refresh()
    }

    fun clearMessage() {
        state = state.copy(message = null)
    }

    fun refresh() {
        viewModelScope.launch {
            repo.vendorApplication().fold(
                onSuccess = { payload ->
                    val application = payload.application
                    state = state.copy(
                        application = application,
                        loading = false,
                        online = application?.isOnline == true
                    )
                    if (application?.status == "Approved") {
                        loadWallet()
                        loadOrders()
                        if (application.isOnline) startPolling()
                    }
                },
                onFailure = {
                    state = state.copy(loading = false)
                    fail(it)
                }
            )
        }
    }

    fun setOnline(online: Boolean) {
        viewModelScope.launch {
            repo.setOnline(online).fold(
                onSuccess = {
                    state = state.copy(online = online)
                    if (online) startPolling() else stopPolling()
                },
                onFailure = { fail(it) }
            )
        }
    }

    fun loadOrders() {
        viewModelScope.launch {
            repo.vendorOrders("active").fold(
                onSuccess = { state = state.copy(orders = it.orders) },
                onFailure = { fail(it) }
            )
        }
    }

    fun loadOrder(id: Long) {
        viewModelScope.launch {
            repo.vendorOrder(id).fold(
                onSuccess = { selectedOrder = it },
                onFailure = { fail(it) }
            )
        }
    }

    fun accept(id: Long) = transition { repo.acceptOrder(id) }

    fun decline(id: Long, reason: String?) = transition { repo.declineOrder(id, reason) }

    fun markOnTheWay(id: Long) = transition { repo.markOnTheWay(id) }

    fun markDelivered(id: Long) = transition { repo.markDelivered(id) }

    fun loadWallet() {
        viewModelScope.launch {
            repo.wallet().fold(
                onSuccess = { state = state.copy(wallet = it) },
                onFailure = { fail(it) }
            )
            repo.transactions().fold(
                onSuccess = { state = state.copy(transactions = it.transactions) },
                onFailure = { }
            )
            repo.payouts().fold(
                onSuccess = { state = state.copy(payouts = it.payouts) },
                onFailure = { }
            )
        }
    }

    fun requestPayout(amount: String) {
        viewModelScope.launch {
            state = state.copy(busy = true)
            repo.requestPayout(amount).fold(
                onSuccess = {
                    state = state.copy(busy = false, message = "Payout requested.")
                    loadWallet()
                },
                onFailure = {
                    state = state.copy(busy = false)
                    fail(it)
                }
            )
        }
    }

    fun updateShop(price: String, radius: String, opensAt: String?, closesAt: String?, note: String?) {
        viewModelScope.launch {
            state = state.copy(busy = true)
            val body = mutableMapOf<String, Any?>()
            price.toDoubleOrNull()?.let { body["price_per_20l"] = it }
            radius.toDoubleOrNull()?.let { body["delivery_radius_km"] = it }
            if (!opensAt.isNullOrBlank()) body["opens_at"] = opensAt
            if (!closesAt.isNullOrBlank()) body["closes_at"] = closesAt
            if (note != null) body["base_note"] = note
            repo.updateVendorProfile(body).fold(
                onSuccess = {
                    state = state.copy(busy = false, message = "Shop profile updated.")
                    refresh()
                },
                onFailure = {
                    state = state.copy(busy = false)
                    fail(it)
                }
            )
        }
    }

    fun applyAsVendor(request: VendorApplicationRequest) {
        viewModelScope.launch {
            state = state.copy(busy = true)
            repo.applyAsVendor(request).fold(
                onSuccess = {
                    state = state.copy(busy = false, message = "Application submitted for review.")
                    refresh()
                },
                onFailure = {
                    state = state.copy(busy = false)
                    fail(it)
                }
            )
        }
    }

    fun logout(onDone: () -> Unit) {
        stopPolling()
        viewModelScope.launch {
            runCatching { repo.logout() }
            SessionStore.clear()
            onDone()
        }
    }

    private fun transition(block: suspend () -> Result<OrderDto>) {
        viewModelScope.launch {
            state = state.copy(busy = true)
            block().fold(
                onSuccess = {
                    state = state.copy(busy = false)
                    selectedOrder = it
                    loadOrders()
                },
                onFailure = {
                    state = state.copy(busy = false)
                    fail(it)
                }
            )
        }
    }

    /** Polls the feed every 10s and sends a heartbeat every 5 minutes. */
    private fun startPolling() {
        if (pollJob?.isActive == true) return
        pollJob = viewModelScope.launch {
            var ticks = 0
            while (isActive && state.online) {
                repo.vendorOrders("active").fold(
                    onSuccess = { state = state.copy(orders = it.orders) },
                    onFailure = { /* transient network errors are ignored while polling */ }
                )
                if (ticks % 30 == 0) {
                    repo.heartbeat()
                }
                ticks++
                delay(10_000)
            }
        }
    }

    private fun stopPolling() {
        pollJob?.cancel()
        pollJob = null
    }

    private fun fail(error: Throwable) {
        state = state.copy(
            message = (error as? ApiException)?.message ?: error.message ?: "Something went wrong."
        )
    }
}
