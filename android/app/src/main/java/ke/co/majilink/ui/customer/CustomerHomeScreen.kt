package ke.co.majilink.ui.customer

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
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.WaterDrop
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.google.android.gms.maps.model.CameraPosition
import com.google.android.gms.maps.model.LatLng
import com.google.maps.android.compose.GoogleMap
import com.google.maps.android.compose.Marker
import com.google.maps.android.compose.rememberCameraPositionState
import com.google.maps.android.compose.rememberMarkerState
import ke.co.majilink.data.api.OrderDto
import ke.co.majilink.data.api.VendorSummaryDto
import ke.co.majilink.ui.components.ActionButton
import ke.co.majilink.ui.components.EmptyState
import ke.co.majilink.ui.components.ErrorBanner
import ke.co.majilink.ui.components.OfflineBanner
import ke.co.majilink.ui.components.OutlineAction
import ke.co.majilink.ui.components.SectionCard
import ke.co.majilink.ui.components.StatusChip

@Composable
fun CustomerHomeScreen(
    viewModel: CustomerViewModel,
    onOpenVendor: (Long) -> Unit,
    onTrackOrder: (Long) -> Unit,
    onOpenLocations: () -> Unit
) {
    val home = viewModel.home
    val locations = viewModel.locations
    var menuOpen by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) { viewModel.loadLocations() }

    Scaffold(
        topBar = {
            Surface(color = MaterialTheme.colorScheme.primary) {
                Row(
                    Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 16.dp, vertical = 12.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Column(Modifier.weight(1f)) {
                        Text(
                            "Delivering to",
                            color = MaterialTheme.colorScheme.onPrimary.copy(alpha = .85f),
                            fontSize = 12.sp
                        )
                        Box {
                            Row(
                                // With no pin saved there is nothing to switch to, so send
                                // the customer straight to the pin screen instead of an empty menu.
                                Modifier.clickable {
                                    if (locations.isEmpty()) onOpenLocations() else menuOpen = true
                                },
                                verticalAlignment = Alignment.CenterVertically
                            ) {
                                Icon(
                                    Icons.Rounded.LocationOn,
                                    contentDescription = null,
                                    tint = MaterialTheme.colorScheme.onPrimary
                                )
                                Spacer(Modifier.width(4.dp))
                                Text(
                                    viewModel.selectedLocation?.alias ?: "Add a home pin",
                                    color = MaterialTheme.colorScheme.onPrimary,
                                    fontWeight = FontWeight.Bold,
                                    fontSize = 18.sp
                                )
                            }
                            DropdownMenu(expanded = menuOpen, onDismissRequest = { menuOpen = false }) {
                                locations.forEach { location ->
                                    DropdownMenuItem(
                                        text = { Text(location.alias) },
                                        onClick = {
                                            menuOpen = false
                                            viewModel.selectLocation(location.id)
                                        }
                                    )
                                }
                                DropdownMenuItem(
                                    text = { Text("Manage pins") },
                                    onClick = {
                                        menuOpen = false
                                        onOpenLocations()
                                    }
                                )
                            }
                        }
                    }
                    Text(
                        "MajiLink",
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
        ) {
            OfflineBanner(home.offline)
            ErrorBanner(viewModel.message) { viewModel.clearMessage() }

            LazyColumn(Modifier.fillMaxSize()) {
                item {
                    MeterCard(
                        order = home.activeOrder,
                        onTrack = { home.activeOrder?.let { onTrackOrder(it.id) } }
                    )
                }
                item {
                    val location = viewModel.selectedLocation
                    if (location != null) {
                        val camera = rememberCameraPositionState {
                            position = CameraPosition.fromLatLngZoom(LatLng(location.lat, location.lng), 14.5f)
                        }
                        GoogleMap(
                            modifier = Modifier
                                .fillMaxWidth()
                                .height(240.dp),
                            cameraPositionState = camera
                        ) {
                            Marker(
                                state = rememberMarkerState(position = LatLng(location.lat, location.lng)),
                                title = location.alias
                            )
                            home.vendors.forEach { vendor ->
                                Marker(
                                    state = rememberMarkerState(position = LatLng(vendor.baseLat, vendor.baseLng)),
                                    title = vendor.businessName,
                                    snippet = "KES ${vendor.pricePer20l} per 20L"
                                )
                            }
                        }
                    }
                }
                item {
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .padding(16.dp),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Text("Vendors near you", fontSize = 18.sp, fontWeight = FontWeight.Bold)
                        Text(
                            "Within ${home.radiusKm.toInt()} km",
                            fontSize = 12.sp,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                }
                if (home.vendors.isEmpty() && !home.loading) {
                    item {
                        EmptyState(
                            title = "No vendors online nearby",
                            body = "Try a wider radius or check again in a few minutes."
                        ) {
                            OutlineAction("Widen the radius", onClick = { viewModel.widenRadius() })
                        }
                    }
                }
                items(home.vendors, key = { it.id }) { vendor ->
                    VendorCard(
                        vendor = vendor,
                        onOpen = { onOpenVendor(vendor.id) },
                        onOrder = { onOpenVendor(vendor.id) }
                    )
                }
                item { Spacer(Modifier.height(24.dp)) }
            }
        }
    }
}

@Composable
private fun MeterCard(order: OrderDto?, onTrack: () -> Unit) {
    SectionCard(Modifier.padding(16.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(
                Modifier
                    .size(42.dp)
                    .background(MaterialTheme.colorScheme.primary.copy(alpha = .12f), CircleShape),
                contentAlignment = Alignment.Center
            ) {
                Icon(Icons.Rounded.WaterDrop, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text("Maji Meter", fontWeight = FontWeight.Bold)
                Text(
                    order?.let { "${it.quantity} jerrycans · ${it.vendor.businessName}" }
                        ?: "No active order right now",
                    fontSize = 13.sp,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
            if (order != null) {
                StatusChip(order.status.name)
            }
        }
        if (order != null) {
            Spacer(Modifier.height(12.dp))
            Row(
                Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically
            ) {
                Text("KES ${order.total}", fontSize = 22.sp, fontWeight = FontWeight.Bold)
                ActionButton(text = "Track order", onClick = onTrack)
            }
        }
    }
}

@Composable
private fun VendorCard(vendor: VendorSummaryDto, onOpen: () -> Unit, onOrder: () -> Unit) {
    Card(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 6.dp)
            .clickable(onClick = onOpen),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        shape = RoundedCornerShape(18.dp)
    ) {
        Column(Modifier.padding(16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(vendor.businessName, fontWeight = FontWeight.Bold, fontSize = 17.sp)
                    Text(
                        vendor.waterSource.name,
                        fontSize = 12.sp,
                        color = MaterialTheme.colorScheme.primary,
                        fontWeight = FontWeight.SemiBold
                    )
                }
                Text("⭐ ${"%.1f".format(vendor.avgRating)}", fontSize = 13.sp)
            }
            Spacer(Modifier.height(6.dp))
            Text(
                buildString {
                    append("${"%.1f".format(vendor.distanceKm)} km")
                    vendor.etaMinutes?.let { append(" · $it min") }
                    append(" · ${vendor.ratingCount} ratings")
                },
                fontSize = 13.sp,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
            Spacer(Modifier.height(12.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("KES ${vendor.pricePer20l}", fontSize = 22.sp, fontWeight = FontWeight.Bold)
                Text(" / 20L", fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                Spacer(Modifier.weight(1f))
                ActionButton(text = "Order", onClick = onOrder)
            }
        }
    }
}
