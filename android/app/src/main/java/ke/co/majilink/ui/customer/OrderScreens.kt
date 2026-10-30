package ke.co.majilink.ui.customer

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Divider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
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
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.google.android.gms.maps.model.CameraPosition
import com.google.android.gms.maps.model.LatLng
import com.google.maps.android.compose.GoogleMap
import com.google.maps.android.compose.Marker
import com.google.maps.android.compose.rememberCameraPositionState
import com.google.maps.android.compose.rememberMarkerState
import ke.co.majilink.ui.components.ErrorBanner
import ke.co.majilink.ui.components.JerrycanStepper
import ke.co.majilink.ui.components.LabeledField
import ke.co.majilink.ui.components.OutlineAction
import ke.co.majilink.ui.components.PriceLine
import ke.co.majilink.ui.components.PrimaryButton
import ke.co.majilink.ui.components.SectionCard
import ke.co.majilink.ui.components.StatusChip
import ke.co.majilink.ui.theme.MajiPalette

@Composable
fun VendorOrderScreen(
    viewModel: CustomerViewModel,
    vendorId: Long,
    onPlaced: (Long) -> Unit,
    onBack: () -> Unit
) {
    var quantity by remember { mutableStateOf(2) }
    var note by remember { mutableStateOf("") }
    var method by remember { mutableStateOf("Cash") }
    val vendor = viewModel.vendorDetail
    val quote = viewModel.quote
    val location = viewModel.selectedLocation

    LaunchedEffect(vendorId) { viewModel.loadVendor(vendorId) }
    LaunchedEffect(vendorId, location?.id, quantity) {
        if (location != null) viewModel.requestQuote(vendorId, location.id, quantity)
    }

    Scaffold(
        topBar = {
            Surface(color = MaterialTheme.colorScheme.primary) {
                Row(
                    Modifier
                        .fillMaxWidth()
                        .padding(16.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    TextButton(onClick = onBack) {
                        Text("Back", color = MaterialTheme.colorScheme.onPrimary)
                    }
                    Text(
                        vendor?.businessName ?: "Order water",
                        color = MaterialTheme.colorScheme.onPrimary,
                        fontWeight = FontWeight.Bold,
                        fontSize = 18.sp
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
            ErrorBanner(viewModel.message) { viewModel.clearMessage() }
            SectionCard {
                Text("How many 20L jerrycans?", fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(8.dp))
                JerrycanStepper(quantity = quantity, onChange = { quantity = it })
                Spacer(Modifier.height(8.dp))
                Text(
                    "Deliver to: ${location?.alias ?: "No pin saved"}",
                    fontSize = 13.sp,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
            Spacer(Modifier.height(14.dp))
            SectionCard {
                Text("Payment", fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(8.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    listOf("Cash", "M-Pesa").forEach { option ->
                        val selected = method == option
                        Surface(
                            shape = RoundedCornerShape(999.dp),
                            color = if (selected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surface,
                            border = BorderStroke(
                                1.dp,
                                if (selected) MaterialTheme.colorScheme.primary
                                else MaterialTheme.colorScheme.outline.copy(alpha = .4f)
                            ),
                            modifier = Modifier.clickable { method = option }
                        ) {
                            Text(
                                option,
                                color = if (selected) MaterialTheme.colorScheme.onPrimary else MaterialTheme.colorScheme.onSurface,
                                fontWeight = FontWeight.SemiBold,
                                modifier = Modifier.padding(horizontal = 18.dp, vertical = 10.dp)
                            )
                        }
                    }
                }
            }
            Spacer(Modifier.height(14.dp))
            SectionCard {
                LabeledField("Note for the vendor (optional)", note, { note = it })
                Spacer(Modifier.height(12.dp))
                Divider()
                Spacer(Modifier.height(8.dp))
                if (quote != null) {
                    PriceLine("Water (${quote.quantity} × KES ${quote.unitPrice})", "KES ${quote.subtotal}")
                    PriceLine("Delivery (${"%.1f".format(quote.distanceKm)} km)", "KES ${quote.deliveryFee}")
                    Spacer(Modifier.height(6.dp))
                    PriceLine("Total", "KES ${quote.total}", emphasise = true)
                }
                Spacer(Modifier.height(12.dp))
                PrimaryButton(
                    text = if (viewModel.busy) "Placing…" else "Confirm order",
                    enabled = !viewModel.busy && location != null,
                    onClick = {
                        location?.let { pin ->
                            viewModel.placeOrder(vendorId, pin.id, quantity, note, method) { order ->
                                onPlaced(order.id)
                            }
                        }
                    }
                )
            }
        }
    }
}

@Composable
fun TrackingScreen(
    viewModel: CustomerViewModel,
    orderId: Long,
    onBack: () -> Unit
) {
    val order = viewModel.tracking
    val context = LocalContext.current
    var ratingOpen by remember { mutableStateOf(false) }
    var stars by remember { mutableStateOf(5) }
    var comment by remember { mutableStateOf("") }

    LaunchedEffect(orderId) {
        viewModel.loadOrder(orderId)
        viewModel.loadActiveOrder()
    }

    Scaffold(
        topBar = {
            Surface(color = MaterialTheme.colorScheme.primary) {
                Row(
                    Modifier
                        .fillMaxWidth()
                        .padding(16.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    TextButton(onClick = onBack) {
                        Text("Back", color = MaterialTheme.colorScheme.onPrimary)
                    }
                    Text(
                        order?.orderNo ?: "Order",
                        color = MaterialTheme.colorScheme.onPrimary,
                        fontWeight = FontWeight.Bold,
                        fontSize = 18.sp
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
            ErrorBanner(viewModel.message) { viewModel.clearMessage() }
            if (order == null) {
                Text("Loading your order…")
                return@Column
            }

            SectionCard {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(order.vendor.businessName, fontWeight = FontWeight.Bold, fontSize = 18.sp)
                        Text(
                            "${order.quantity} jerrycans · ${order.payment.method ?: "Cash"}",
                            fontSize = 13.sp,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                    StatusChip(order.status.name)
                }
                Spacer(Modifier.height(12.dp))
                Text("KES ${order.total}", fontSize = 22.sp, fontWeight = FontWeight.Bold)
            }

            Spacer(Modifier.height(14.dp))
            SectionCard {
                Text("Progress", fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(8.dp))
                val steps = listOf("Pending", "Accepted", "On the way", "Delivered")
                val currentIndex = steps.indexOf(order.status.name)
                steps.forEachIndexed { index, step ->
                    val active = currentIndex >= 0 && index <= currentIndex
                    Row(
                        verticalAlignment = Alignment.CenterVertically,
                        modifier = Modifier.padding(vertical = 4.dp)
                    ) {
                        Box(
                            Modifier
                                .size(12.dp)
                                .background(
                                    if (active) MaterialTheme.colorScheme.primary
                                    else MaterialTheme.colorScheme.outline.copy(alpha = .35f),
                                    CircleShape
                                )
                        )
                        Spacer(Modifier.width(10.dp))
                        Text(
                            step,
                            fontWeight = if (index == currentIndex) FontWeight.Bold else FontWeight.Normal,
                            color = if (active) MaterialTheme.colorScheme.onSurface
                            else MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                }
            }

            val delivery = order.delivery
            if (delivery.lat != null && delivery.lng != null) {
                Spacer(Modifier.height(14.dp))
                val camera = rememberCameraPositionState {
                    position = CameraPosition.fromLatLngZoom(LatLng(delivery.lat, delivery.lng), 14f)
                }
                GoogleMap(
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(220.dp),
                    cameraPositionState = camera
                ) {
                    Marker(
                        state = rememberMarkerState(position = LatLng(delivery.lat, delivery.lng)),
                        title = delivery.alias ?: "Your gate"
                    )
                    Marker(
                        state = rememberMarkerState(
                            position = LatLng(order.vendor.baseLat, order.vendor.baseLng)
                        ),
                        title = order.vendor.businessName
                    )
                }
            }

            Spacer(Modifier.height(14.dp))
            SectionCard {
                Text("Vendor", fontWeight = FontWeight.Bold)
                Text(order.vendor.businessName)
                order.vendor.phone?.let { phone ->
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(phone, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Spacer(Modifier.width(8.dp))
                        TextButton(onClick = {
                            context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$phone")))
                        }) {
                            Icon(Icons.Rounded.Call, contentDescription = null)
                            Spacer(Modifier.width(4.dp))
                            Text("Call")
                        }
                    }
                }
                Spacer(Modifier.height(8.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    if (order.actions.navigate && delivery.lat != null) {
                        OutlineAction("Navigate", onClick = {
                            val uri = Uri.parse("google.navigation:q=${delivery.lat},${delivery.lng}")
                            context.startActivity(Intent(Intent.ACTION_VIEW, uri))
                        })
                    }
                    if (order.actions.payMpesa) {
                        OutlineAction("Pay with M-Pesa", onClick = {
                            viewModel.payWithMpesa(order.id, null)
                        })
                    }
                }
                Spacer(Modifier.height(8.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    if (order.actions.cancel) {
                        OutlineAction("Cancel order", danger = true, onClick = {
                            viewModel.cancelOrder(order.id, "Changed my mind")
                        })
                    }
                    if (order.actions.rate) {
                        Button(
                            onClick = { ratingOpen = true },
                            colors = ButtonDefaults.buttonColors(containerColor = MajiPalette.Warning),
                            modifier = Modifier.height(52.dp)
                        ) {
                            Text("Rate vendor", color = MajiPalette.Text, fontWeight = FontWeight.Bold)
                        }
                    }
                }
            }

            if (order.history.isNotEmpty()) {
                Spacer(Modifier.height(14.dp))
                SectionCard {
                    Text("History", fontWeight = FontWeight.Bold)
                    order.history.forEach { step ->
                        Text(
                            "${step.status} · ${step.createdAt}",
                            fontSize = 13.sp,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                }
            }
        }
    }

    if (ratingOpen) {
        AlertDialog(
            onDismissRequest = { ratingOpen = false },
            title = { Text("Rate this delivery") },
            text = {
                Column {
                    JerrycanStepper(quantity = stars, onChange = { stars = it }, min = 1, max = 5)
                    Text("Stars", fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    Spacer(Modifier.height(8.dp))
                    OutlinedTextField(
                        value = comment,
                        onValueChange = { comment = it },
                        label = { Text("Comment (optional)") }
                    )
                }
            },
            confirmButton = {
                TextButton(onClick = {
                    viewModel.rateOrder(orderId, stars, comment)
                    ratingOpen = false
                }) { Text("Submit") }
            },
            dismissButton = {
                TextButton(onClick = { ratingOpen = false }) { Text("Cancel") }
            }
        )
    }
}
