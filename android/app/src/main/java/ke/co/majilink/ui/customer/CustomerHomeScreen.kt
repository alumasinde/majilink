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
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.Map
import androidx.compose.material.icons.rounded.MyLocation
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import ke.co.majilink.data.api.OrderDto
import ke.co.majilink.data.api.VendorSummaryDto
import ke.co.majilink.ui.components.ActionButton
import ke.co.majilink.ui.components.EmptyState
import ke.co.majilink.ui.components.ErrorBanner
import ke.co.majilink.ui.components.OfflineBanner
import ke.co.majilink.ui.components.PrimaryButton
import ke.co.majilink.ui.components.SectionCard
import ke.co.majilink.ui.components.StatusChip
import ke.co.majilink.ui.theme.MajiPalette

@Composable
fun CustomerHomeScreen(
    viewModel: CustomerViewModel,
    onOpenVendor: (Long) -> Unit,
    onTrackOrder: (Long) -> Unit,
    onOpenLocations: () -> Unit
) {
    val home = viewModel.home
    val locations = viewModel.locations
    var pinMenuOpen by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) { viewModel.loadLocations() }

    Scaffold { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
        ) {
            HomeHeader(
                alias = viewModel.selectedLocation?.alias,
                hasPins = locations.isNotEmpty(),
                menuOpen = pinMenuOpen,
                onMenuChange = { pinMenuOpen = it },
                onOpenLocations = onOpenLocations,
                locations = locations,
                onSelect = { viewModel.selectLocation(it) }
            )
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
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .padding(horizontal = 16.dp, vertical = 8.dp),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Text("Vendors near you", fontSize = 18.sp, fontWeight = FontWeight.Bold)
                        RadiusPicker(
                            radiusKm = home.radiusKm,
                            maxRadiusKm = home.maxRadiusKm,
                            onPick = { viewModel.setRadius(it) }
                        )
                    }
                }
                if (home.vendors.isEmpty() && !home.loading) {
                    item {
                        EmptyState(
                            title = "No vendors nearby",
                            body = "Try updating your location or check back later.",
                            icon = Icons.Rounded.LocationOn
                        ) {
                            PrimaryButton(
                                "Update Location",
                                icon = Icons.Rounded.MyLocation,
                                onClick = onOpenLocations
                            )
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

/** Green gradient card header: current pin on the left, brand chip on the right. */
@Composable
private fun HomeHeader(
    alias: String?,
    hasPins: Boolean,
    menuOpen: Boolean,
    onMenuChange: (Boolean) -> Unit,
    onOpenLocations: () -> Unit,
    locations: List<ke.co.majilink.data.api.LocationDto>,
    onSelect: (Long) -> Unit
) {
    Box(
        Modifier
            .fillMaxWidth()
            .padding(horizontal = 12.dp, vertical = 10.dp)
            .clip(RoundedCornerShape(20.dp))
            .background(
                Brush.horizontalGradient(
                    listOf(Color(0xFF1E9E6A), Color(0xFF0F5C3D))
                )
            )
            .padding(horizontal = 16.dp, vertical = 14.dp)
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(
                Icons.Rounded.LocationOn,
                contentDescription = null,
                tint = Color.White.copy(alpha = .95f)
            )
            Spacer(Modifier.width(8.dp))
            Column(Modifier.weight(1f)) {
                Text(
                    "Delivering to",
                    color = Color.White.copy(alpha = .8f),
                    fontSize = 12.sp
                )
                Box {
                    Row(
                        // With no pin saved there is nothing to switch to, so send
                        // the customer straight to the pin screen instead of an empty menu.
                        Modifier.clickable {
                            if (!hasPins) onOpenLocations() else onMenuChange(true)
                        },
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Text(
                            alias ?: "Add a home pin",
                            color = Color.White,
                            fontWeight = FontWeight.Bold,
                            fontSize = 18.sp
                        )
                        Icon(
                            Icons.Rounded.KeyboardArrowDown,
                            contentDescription = "Choose a pin",
                            tint = Color.White
                        )
                    }
                    DropdownMenu(expanded = menuOpen, onDismissRequest = { onMenuChange(false) }) {
                        locations.forEach { location ->
                            DropdownMenuItem(
                                text = { Text(location.alias) },
                                onClick = {
                                    onMenuChange(false)
                                    onSelect(location.id)
                                }
                            )
                        }
                        DropdownMenuItem(
                            text = { Text("Manage pins") },
                            onClick = {
                                onMenuChange(false)
                                onOpenLocations()
                            }
                        )
                    }
                }
            }
            Surface(
                color = Color.White.copy(alpha = .18f),
                shape = RoundedCornerShape(999.dp)
            ) {
                Row(
                    Modifier.padding(horizontal = 12.dp, vertical = 6.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Icon(
                        Icons.Rounded.Map,
                        contentDescription = null,
                        tint = Color.White,
                        modifier = Modifier.size(14.dp)
                    )
                    Spacer(Modifier.width(6.dp))
                    Text("Majilink", color = Color.White, fontWeight = FontWeight.Bold, fontSize = 13.sp)
                }
            }
        }
    }
}

@Composable
private fun RadiusPicker(radiusKm: Double, maxRadiusKm: Double, onPick: (Double) -> Unit) {
    var open by remember { mutableStateOf(false) }
    val options = listOf(1.0, 2.0, 3.0, 5.0).filter { it <= maxRadiusKm }
    Box {
        Row(
            Modifier.clickable { open = true },
            verticalAlignment = Alignment.CenterVertically
        ) {
            Text(
                "Within ${radiusKm.toInt()} km",
                fontSize = 13.sp,
                color = MajiPalette.Accent,
                fontWeight = FontWeight.SemiBold
            )
            Icon(
                Icons.Rounded.KeyboardArrowDown,
                contentDescription = "Change radius",
                tint = MajiPalette.Accent,
                modifier = Modifier.size(16.dp)
            )
        }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            options.forEach { km ->
                DropdownMenuItem(
                    text = { Text("${km.toInt()} km") },
                    onClick = {
                        open = false
                        onPick(km)
                    }
                )
            }
        }
    }
}

@Composable
private fun MeterCard(order: OrderDto?, onTrack: () -> Unit) {
    SectionCard(Modifier.padding(horizontal = 16.dp, vertical = 6.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(
                Modifier
                    .size(46.dp)
                    .background(MajiPalette.Accent.copy(alpha = .14f), CircleShape),
                contentAlignment = Alignment.Center
            ) {
                Icon(
                    Icons.Rounded.WaterDrop,
                    contentDescription = null,
                    tint = MajiPalette.Accent,
                    modifier = Modifier.size(26.dp)
                )
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
