package ke.co.majilink.ui.vendor

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.Navigation
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Divider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import ke.co.majilink.data.SessionStore
import ke.co.majilink.ui.components.EmptyState
import ke.co.majilink.ui.components.ErrorBanner
import ke.co.majilink.ui.components.LabeledField
import ke.co.majilink.ui.components.OutlineAction
import ke.co.majilink.ui.components.PrimaryButton
import ke.co.majilink.ui.components.SectionCard
import ke.co.majilink.ui.components.StatusChip
import ke.co.majilink.ui.theme.MajiPalette

@Composable
fun VendorHomeScreen(viewModel: VendorViewModel, onOpenOrder: (Long) -> Unit) {
    val state = viewModel.state
    val application = state.application
    val approved = application?.status == "Approved"

    LaunchedEffect(Unit) { viewModel.refresh() }

    Scaffold(
        topBar = {
            Surface(color = MaterialTheme.colorScheme.primary) {
                Row(
                    Modifier
                        .fillMaxWidth()
                        .padding(16.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Column(Modifier.weight(1f)) {
                        Text(
                            "Vendor mode",
                            color = MaterialTheme.colorScheme.onPrimary.copy(alpha = .85f),
                            fontSize = 12.sp
                        )
                        Text(
                            application?.businessName ?: "Your water shop",
                            color = MaterialTheme.colorScheme.onPrimary,
                            fontWeight = FontWeight.Bold,
                            fontSize = 18.sp
                        )
                    }
                    StatusChip(application?.status ?: "Not applied")
                }
            }
        }
    ) { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
        ) {
            ErrorBanner(state.message) { viewModel.clearMessage() }

            if (!approved) {
                Column(Modifier.padding(16.dp)) {
                    EmptyState(
                        title = when (application?.status) {
                            "Pending" -> "Application under review"
                            "Rejected" -> "Application needs changes"
                            "Suspended" -> "Shop suspended"
                            else -> "Become a MajiLink vendor"
                        },
                        body = application?.rejectionReason
                            ?: "Once approved you can go online and receive water orders from homes around your shop."
                    ) {
                        if (application == null || application.status == "Rejected") {
                            PrimaryButton("Apply to become a vendor", onClick = {
                                // The application form lives on the web portal in v1.
                            }, enabled = false)
                        }
                    }
                }
                return@Column
            }

            SectionCard(Modifier.padding(16.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(
                            if (state.online) "You are online" else "You are offline",
                            fontWeight = FontWeight.Bold,
                            fontSize = 20.sp
                        )
                        Text(
                            if (state.online) "Heartbeat running every 5 minutes"
                            else "Go online to receive orders",
                            fontSize = 13.sp,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                    Switch(
                        checked = state.online,
                        onCheckedChange = { viewModel.setOnline(it) },
                        modifier = Modifier.size(width = 64.dp, height = 48.dp)
                    )
                }
            }

            if (state.orders.isEmpty()) {
                EmptyState("No active orders", "New orders appear here with a countdown to accept.")
            } else {
                LazyColumn(Modifier.fillMaxSize()) {
                    items(state.orders, key = { it.id }) { order ->
                        SectionCard(Modifier.padding(horizontal = 16.dp, vertical = 6.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text(
                                        "${order.customer.firstName} · ${order.quantity} jerrycans",
                                        fontWeight = FontWeight.Bold,
                                        fontSize = 17.sp
                                    )
                                    Text(
                                        "KES ${order.total} · ${"%.1f".format(order.distanceKm)} km away",
                                        fontSize = 13.sp,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant
                                    )
                                }
                                StatusChip(order.status.name)
                            }
                            if (order.status.name == "Pending") {
                                Spacer(Modifier.height(10.dp))
                                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                    Button(
                                        onClick = { viewModel.accept(order.id) },
                                        modifier = Modifier
                                            .weight(1f)
                                            .height(56.dp),
                                        colors = ButtonDefaults.buttonColors(
                                            containerColor = MaterialTheme.colorScheme.primary
                                        ),
                                        border = BorderStroke(2.dp, MaterialTheme.colorScheme.onPrimary.copy(alpha = .6f))
                                    ) {
                                        Text("Accept", fontWeight = FontWeight.Bold, fontSize = 17.sp)
                                    }
                                    OutlineAction(
                                        "Decline",
                                        danger = true,
                                        onClick = { viewModel.decline(order.id, "Too far right now") },
                                        modifier = Modifier.weight(1f)
                                    )
                                }
                            } else {
                                Spacer(Modifier.height(8.dp))
                                OutlineAction("Open order", onClick = { onOpenOrder(order.id) })
                            }
                        }
                    }
                    item { Spacer(Modifier.height(24.dp)) }
                }
            }
        }
    }
}

@Composable
fun VendorOrderScreen(viewModel: VendorViewModel, orderId: Long, onBack: () -> Unit) {
    val order = viewModel.selectedOrder
    val context = LocalContext.current

    LaunchedEffect(orderId) { viewModel.loadOrder(orderId) }

    Scaffold(
        topBar = {
            Surface(color = MaterialTheme.colorScheme.primary) {
                Row(Modifier.fillMaxWidth().padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                    TextButton(onClick = onBack) {
                        Text("Back", color = MaterialTheme.colorScheme.onPrimary)
                    }
                    Text(
                        order?.orderNo ?: "Order",
                        color = MaterialTheme.colorScheme.onPrimary,
                        fontWeight = FontWeight.Bold
                    )
                }
            }
        }
    ) { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(16.dp)
        ) {
            ErrorBanner(viewModel.state.message) { viewModel.clearMessage() }
            if (order == null) {
                Text("Loading…")
                return@Column
            }

            SectionCard {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(order.delivery.alias ?: "Address after acceptance", fontWeight = FontWeight.Bold, fontSize = 18.sp)
                        Text(
                            "${order.quantity} jerrycans · ${order.customer.firstName}",
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                    StatusChip(order.status.name)
                }
                Spacer(Modifier.height(8.dp))
                Text("KES ${order.total}", fontSize = 22.sp, fontWeight = FontWeight.Bold)
                Text(
                    "Payment: ${order.payment.method ?: "Cash"} (${order.payment.status ?: "unpaid"})",
                    fontSize = 13.sp,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
                if ((order.payment.method ?: "Cash") == "Cash") {
                    Surface(
                        color = MajiPalette.Warning.copy(alpha = .15f),
                        shape = RoundedCornerShape(10.dp),
                        modifier = Modifier.padding(top = 8.dp)
                    ) {
                        Text(
                            "Collect cash at the gate.",
                            modifier = Modifier.padding(horizontal = 12.dp, vertical = 8.dp),
                            fontWeight = FontWeight.SemiBold,
                            color = MajiPalette.Text
                        )
                    }
                }
            }

            Spacer(Modifier.height(12.dp))
            SectionCard {
                Text("Delivery", fontWeight = FontWeight.Bold)
                val lat = order.delivery.lat
                val lng = order.delivery.lng
                if (lat != null && lng != null) {
                    Text("Pin: ${"%.5f".format(lat)}, ${"%.5f".format(lng)}")
                    order.delivery.landmark?.let {
                        Spacer(Modifier.height(4.dp))
                        Text("Landmark: $it", fontWeight = FontWeight.SemiBold)
                    }
                    Spacer(Modifier.height(8.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        Button(
                            onClick = {
                                context.startActivity(
                                    Intent(
                                        Intent.ACTION_VIEW,
                                        Uri.parse("google.navigation:q=$lat,$lng")
                                    )
                                )
                            },
                            modifier = Modifier.height(52.dp)
                        ) {
                            Icon(Icons.Rounded.Navigation, contentDescription = null)
                            Spacer(Modifier.width(6.dp))
                            Text("Navigate")
                        }
                        order.customer.phone?.let { phone ->
                            OutlineAction("Call customer", onClick = {
                                context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$phone")))
                            })
                        }
                    }
                } else {
                    Text(
                        "The exact pin and phone unlock once you accept.",
                        fontSize = 13.sp,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
                order.note?.let {
                    Spacer(Modifier.height(8.dp))
                    Text("Note: $it", fontSize = 13.sp)
                }
            }

            Spacer(Modifier.height(12.dp))
            SectionCard {
                Text("Progress", fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(8.dp))
                when (order.status.name) {
                    "Pending" -> Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        PrimaryButton("Accept", onClick = { viewModel.accept(order.id) })
                        OutlineAction("Decline", danger = true, onClick = {
                            viewModel.decline(order.id, "Cannot deliver right now")
                        })
                    }
                    "Accepted" -> PrimaryButton("I am on the way", onClick = {
                        viewModel.markOnTheWay(order.id)
                    })
                    "On the way" -> PrimaryButton("Mark delivered", onClick = {
                        viewModel.markDelivered(order.id)
                    })
                    else -> Text("This order is ${order.status.name.lowercase()}.")
                }
            }
        }
    }
}

@Composable
fun VendorWalletScreen(viewModel: VendorViewModel) {
    val state = viewModel.state
    var amount by remember { mutableStateOf("") }

    LaunchedEffect(Unit) { viewModel.loadWallet() }

    Scaffold(
        topBar = {
            Surface(color = MaterialTheme.colorScheme.primary) {
                Text(
                    "Wallet",
                    color = MaterialTheme.colorScheme.onPrimary,
                    fontWeight = FontWeight.Bold,
                    fontSize = 20.sp,
                    modifier = Modifier.padding(16.dp)
                )
            }
        }
    ) { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(16.dp)
        ) {
            ErrorBanner(state.message) { viewModel.clearMessage() }
            SectionCard {
                Text("Available balance", color = MaterialTheme.colorScheme.onSurfaceVariant, fontSize = 13.sp)
                Text("KES ${state.wallet?.available ?: "0.00"}", fontSize = 34.sp, fontWeight = FontWeight.Bold)
                Text(
                    "Today: KES ${state.wallet?.earnedToday ?: "0.00"} · This week: KES ${state.wallet?.earnedWeek ?: "0.00"}",
                    fontSize = 13.sp,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
                Spacer(Modifier.height(12.dp))
                Divider()
                Spacer(Modifier.height(12.dp))
                LabeledField(
                    "Request a payout (KES)",
                    amount,
                    { amount = it.filter { ch -> ch.isDigit() || ch == '.' } },
                    keyboardType = KeyboardType.Decimal
                )
                Spacer(Modifier.height(8.dp))
                PrimaryButton(
                    if (state.busy) "Sending…" else "Request payout",
                    enabled = !state.busy && amount.isNotBlank(),
                    onClick = {
                        viewModel.requestPayout(amount)
                        amount = ""
                    }
                )
            }

            Spacer(Modifier.height(12.dp))
            SectionCard {
                Text("Transactions", fontWeight = FontWeight.Bold)
                if (state.transactions.isEmpty()) {
                    Text("No transactions yet.", fontSize = 13.sp)
                }
                state.transactions.take(20).forEach { tx ->
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .padding(vertical = 6.dp),
                        horizontalArrangement = Arrangement.SpaceBetween
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text(tx.type, fontWeight = FontWeight.SemiBold)
                            Text(
                                tx.createdAt,
                                fontSize = 11.sp,
                                color = MaterialTheme.colorScheme.onSurfaceVariant
                            )
                        }
                        Text("KES ${tx.amount}", fontWeight = FontWeight.Bold)
                    }
                }
            }

            Spacer(Modifier.height(12.dp))
            SectionCard {
                Text("Payouts", fontWeight = FontWeight.Bold)
                if (state.payouts.isEmpty()) {
                    Text("No payouts requested yet.", fontSize = 13.sp)
                }
                state.payouts.forEach { payout ->
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .padding(vertical = 6.dp),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Column {
                            Text("KES ${payout.amount}", fontWeight = FontWeight.SemiBold)
                            Text(payout.requestedAt, fontSize = 11.sp)
                        }
                        StatusChip(payout.status)
                    }
                }
            }
        }
    }
}

@Composable
fun VendorShopScreen(viewModel: VendorViewModel) {
    val state = viewModel.state
    val application = state.application
    var price by remember(application?.id) { mutableStateOf(application?.pricePer20l ?: "") }
    var radius by remember(application?.id) { mutableStateOf(application?.deliveryRadiusKm ?: "") }
    var note by remember(application?.id) { mutableStateOf("") }

    Scaffold(
        topBar = {
            Surface(color = MaterialTheme.colorScheme.primary) {
                Text(
                    "Shop profile",
                    color = MaterialTheme.colorScheme.onPrimary,
                    fontWeight = FontWeight.Bold,
                    fontSize = 20.sp,
                    modifier = Modifier.padding(16.dp)
                )
            }
        }
    ) { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(16.dp)
        ) {
            ErrorBanner(state.message) { viewModel.clearMessage() }
            SectionCard {
                Text(application?.businessName ?: "Shop", fontWeight = FontWeight.Bold, fontSize = 18.sp)
                Text(
                    application?.waterSource ?: "",
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    fontSize = 13.sp
                )
                Spacer(Modifier.height(12.dp))
                LabeledField(
                    "Price per 20L (KES)",
                    price,
                    { price = it },
                    keyboardType = KeyboardType.Decimal
                )
                Spacer(Modifier.height(8.dp))
                LabeledField(
                    "Delivery radius (km)",
                    radius,
                    { radius = it },
                    keyboardType = KeyboardType.Decimal
                )
                Spacer(Modifier.height(8.dp))
                LabeledField(
                    "Landmark note",
                    note,
                    { note = it },
                    placeholder = "Next to the stage"
                )
                Spacer(Modifier.height(8.dp))
                Text(
                    "Price and hours never need re-approval. Moving the shop pin does.",
                    fontSize = 12.sp,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
                Spacer(Modifier.height(12.dp))
                PrimaryButton(
                    if (state.busy) "Saving…" else "Save changes",
                    enabled = !state.busy,
                    onClick = { viewModel.updateShop(price, radius, null, null, note.ifBlank { null }) }
                )
            }
        }
    }
}

@Composable
fun VendorAccountScreen(
    viewModel: VendorViewModel,
    onSwitchMode: () -> Unit,
    onLoggedOut: () -> Unit
) {
    val user = SessionStore.user()
    val application = viewModel.state.application

    Scaffold(
        topBar = {
            Surface(color = MaterialTheme.colorScheme.primary) {
                Text(
                    "Account",
                    color = MaterialTheme.colorScheme.onPrimary,
                    fontWeight = FontWeight.Bold,
                    fontSize = 20.sp,
                    modifier = Modifier.padding(16.dp)
                )
            }
        }
    ) { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(16.dp)
        ) {
            SectionCard {
                Text(
                    "${user?.firstName ?: ""} ${user?.lastName ?: ""}".trim().ifBlank { "Vendor" },
                    fontWeight = FontWeight.Bold,
                    fontSize = 20.sp
                )
                Text(user?.phone ?: "", color = MaterialTheme.colorScheme.onSurfaceVariant)
                application?.let {
                    Spacer(Modifier.height(6.dp))
                    Text("${it.businessName} · ${it.status}", fontSize = 13.sp)
                }
            }
            Spacer(Modifier.height(12.dp))
            SectionCard {
                Text("Mode", fontWeight = FontWeight.Bold)
                Text(
                    "Vendor mode uses the green theme. Customer mode is blue.",
                    fontSize = 13.sp,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
                Spacer(Modifier.height(8.dp))
                OutlineAction("Switch to customer mode", onClick = onSwitchMode)
            }
            Spacer(Modifier.height(12.dp))
            SectionCard {
                Text("Support", fontWeight = FontWeight.Bold)
                Text("Call +254 700 000 001", fontSize = 13.sp)
            }
            Spacer(Modifier.height(16.dp))
            OutlineAction("Sign out", danger = true, onClick = {
                viewModel.logout { onLoggedOut() }
            }, modifier = Modifier.fillMaxWidth())
        }
    }
}
