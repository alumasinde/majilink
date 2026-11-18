package ke.co.majilink.ui.customer

import android.Manifest
import android.annotation.SuppressLint
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Logout
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Assignment
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Devices
import androidx.compose.material.icons.rounded.EventRepeat
import androidx.compose.material.icons.rounded.Fingerprint
import androidx.compose.material.icons.rounded.LightMode
import androidx.compose.material.icons.rounded.Lightbulb
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.ShoppingCart
import androidx.compose.material.icons.rounded.SupportAgent
import androidx.compose.material.icons.rounded.VerifiedUser
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Divider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
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
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import ke.co.majilink.data.SessionStore
import ke.co.majilink.ui.components.ActionButton
import ke.co.majilink.ui.components.EmptyState
import ke.co.majilink.ui.components.ErrorBanner
import ke.co.majilink.ui.components.LabeledField
import ke.co.majilink.ui.components.ListSkeleton
import ke.co.majilink.ui.components.MajiMap
import ke.co.majilink.ui.components.MapMarker
import ke.co.majilink.ui.components.MapPoint
import ke.co.majilink.ui.components.OutlineAction
import ke.co.majilink.ui.components.PrimaryButton
import ke.co.majilink.ui.components.ScreenTopBar
import ke.co.majilink.ui.components.SectionCard
import ke.co.majilink.ui.components.StatusChip

@Composable
fun CustomerOrdersScreen(
    viewModel: CustomerViewModel,
    onOpenOrder: (Long) -> Unit,
    onReorder: (Long) -> Unit,
    onBrowse: () -> Unit
) {
    var filter by remember { mutableStateOf("all") }
    LaunchedEffect(Unit) { viewModel.loadOrders() }
    val orders = viewModel.orders.filter {
        when (filter) {
            "active" -> !it.status.isFinal
            "history" -> it.status.isFinal
            else -> true
        }
    }

    Scaffold(
        topBar = { ScreenTopBar("My orders") }
    ) { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
        ) {
            ErrorBanner(viewModel.message) { viewModel.clearMessage() }
            Row(
                Modifier
                    .fillMaxWidth()
                    .padding(horizontal = 16.dp, vertical = 10.dp),
                horizontalArrangement = Arrangement.spacedBy(10.dp)
            ) {
                listOf("all" to "All", "active" to "Active", "history" to "History").forEach { (key, label) ->
                    val selected = filter == key
                    Surface(
                        shape = RoundedCornerShape(999.dp),
                        color = if (selected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surface,
                        modifier = Modifier.clickable { filter = key }
                    ) {
                        Text(
                            label,
                            color = if (selected) MaterialTheme.colorScheme.onPrimary else MaterialTheme.colorScheme.onSurface,
                            modifier = Modifier.padding(horizontal = 20.dp, vertical = 9.dp),
                            fontWeight = FontWeight.SemiBold
                        )
                    }
                }
            }

            if (viewModel.ordersLoading && viewModel.orders.isEmpty()) {
                ListSkeleton(rows = 4)
            } else if (orders.isEmpty()) {
                EmptyState(
                    "No orders yet",
                    "Your water deliveries will appear here.",
                    icon = Icons.Rounded.Assignment
                ) {
                    PrimaryButton("Browse Vendors", icon = Icons.Rounded.ShoppingCart, onClick = onBrowse)
                }
            } else {
                LazyColumn(Modifier.fillMaxSize()) {
                    items(orders, key = { it.id }) { order ->
                        SectionCard(Modifier.padding(horizontal = 16.dp, vertical = 6.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text(order.orderNo, fontWeight = FontWeight.Bold)
                                    Text(
                                        "${order.vendor.businessName} · ${order.quantity} jerrycans",
                                        fontSize = 13.sp,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant
                                    )
                                    Text(
                                        order.requestedAt.orEmpty(),
                                        fontSize = 12.sp,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant
                                    )
                                }
                                Column(horizontalAlignment = Alignment.End) {
                                    StatusChip(order.status.name)
                                    Spacer(Modifier.height(6.dp))
                                    Text("KES ${order.total}", fontWeight = FontWeight.Bold, fontSize = 22.sp)
                                }
                            }
                            Spacer(Modifier.height(10.dp))
                            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                OutlineAction("Open", onClick = { onOpenOrder(order.id) })
                                if (order.status.isFinal) {
                                    ActionButton("Re-order", onClick = { onReorder(order.vendor.id) })
                                }
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
fun ScheduleScreen(viewModel: CustomerViewModel, onBack: () -> Unit) {
    var addOpen by remember { mutableStateOf(false) }
    var quantity by remember { mutableStateOf(2) }
    var frequency by remember { mutableStateOf(1L) }
    val vendors = viewModel.home.vendors

    LaunchedEffect(Unit) {
        viewModel.loadSchedules()
        if (viewModel.home.vendors.isEmpty()) viewModel.loadLocations()
    }

    Scaffold(
        topBar = {
            ScreenTopBar(
                "Repeat deliveries",
                onBack = onBack,
                trailing = {
                    Icon(
                        Icons.Rounded.EventRepeat,
                        contentDescription = null,
                        tint = MaterialTheme.colorScheme.primary,
                        modifier = Modifier.size(24.dp)
                    )
                }
            )
        }
    ) { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(vertical = 8.dp)
        ) {
            ErrorBanner(viewModel.message) { viewModel.clearMessage() }
            if (viewModel.schedulesLoading && viewModel.schedules.isEmpty()) {
                ListSkeleton(rows = 3)
            } else if (viewModel.schedules.isEmpty()) {
                EmptyState(
                    "No repeat deliveries",
                    "Set a weekly or monthly delivery so you never run dry.",
                    icon = Icons.Rounded.CalendarMonth
                ) {
                    PrimaryButton("Add a schedule", icon = Icons.Rounded.Add, onClick = { addOpen = true })
                }
                SectionCard(Modifier.padding(horizontal = 16.dp, vertical = 6.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(
                            Icons.Rounded.Lightbulb,
                            contentDescription = null,
                            tint = MaterialTheme.colorScheme.primary,
                            modifier = Modifier.size(22.dp)
                        )
                        Spacer(Modifier.width(10.dp))
                        Column {
                            Text("Tip", fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.primary)
                            Text(
                                "Schedule regular deliveries and stay topped up automatically.",
                                fontSize = 13.sp,
                                color = MaterialTheme.colorScheme.onSurfaceVariant
                            )
                        }
                    }
                }
            } else {
                LazyColumn(Modifier.weight(1f)) {
                    items(viewModel.schedules, key = { it.id }) { schedule ->
                        SectionCard(Modifier.padding(horizontal = 16.dp, vertical = 6.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text(schedule.businessName, fontWeight = FontWeight.Bold)
                                    Text(
                                        "${schedule.quantity} jerrycans · ${schedule.frequency}",
                                        fontSize = 13.sp,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant
                                    )
                                    Text(
                                        "Next: ${schedule.nextRunAt}",
                                        fontSize = 12.sp,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant
                                    )
                                }
                                IconButton(onClick = { viewModel.deleteSchedule(schedule.id) }) {
                                    Icon(Icons.Rounded.Delete, contentDescription = "Delete schedule")
                                }
                            }
                        }
                    }
                    item { Spacer(Modifier.height(16.dp)) }
                }
                PrimaryButton(
                    "Add another schedule",
                    onClick = { addOpen = true },
                    modifier = Modifier.padding(16.dp)
                )
            }
        }
    }

    if (addOpen) {
        var vendorId by remember { mutableStateOf(vendors.firstOrNull()?.id ?: 0L) }
        AlertDialog(
            onDismissRequest = { addOpen = false },
            title = { Text("New repeat delivery") },
            text = {
                Column {
                    Text("Vendor", fontWeight = FontWeight.SemiBold)
                    vendors.forEach { vendor ->
                        Row(
                            Modifier
                                .fillMaxWidth()
                                .clickable { vendorId = vendor.id }
                                .padding(vertical = 6.dp),
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            Text(
                                if (vendorId == vendor.id) "●" else "○",
                                color = MaterialTheme.colorScheme.primary
                            )
                            Spacer(Modifier.width(8.dp))
                            Text(vendor.businessName)
                        }
                    }
                    Spacer(Modifier.height(8.dp))
                    Text("How often", fontWeight = FontWeight.SemiBold)
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        listOf(1L to "Weekly", 2L to "Every 2 weeks", 3L to "Monthly").forEach { (id, label) ->
                            Surface(
                                shape = RoundedCornerShape(999.dp),
                                color = if (frequency == id) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surface,
                                modifier = Modifier.clickable { frequency = id }
                            ) {
                                Text(
                                    label,
                                    color = if (frequency == id) MaterialTheme.colorScheme.onPrimary
                                    else MaterialTheme.colorScheme.onSurface,
                                    fontSize = 12.sp,
                                    modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp)
                                )
                            }
                        }
                    }
                    Spacer(Modifier.height(8.dp))
                    Text("Jerrycans: $quantity")
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        OutlineAction("−", onClick = { if (quantity > 1) quantity-- })
                        OutlineAction("+", onClick = { if (quantity < 20) quantity++ })
                    }
                }
            },
            confirmButton = {
                TextButton(onClick = {
                    val location = viewModel.selectedLocation
                    if (location != null && vendorId != 0L) {
                        viewModel.createSchedule(vendorId, location.id, frequency, quantity)
                    }
                    addOpen = false
                }) { Text("Create") }
            },
            dismissButton = {
                TextButton(onClick = { addOpen = false }) { Text("Cancel") }
            }
        )
    }
}

@SuppressLint("MissingPermission")
@Composable
fun LocationsScreen(viewModel: CustomerViewModel) {
    val context = LocalContext.current
    var addOpen by remember { mutableStateOf(false) }
    var alias by remember { mutableStateOf("") }
    var note by remember { mutableStateOf("") }
    var lat by remember { mutableStateOf<Double?>(null) }
    var lng by remember { mutableStateOf<Double?>(null) }
    var locationError by remember { mutableStateOf<String?>(null) }

    // Nairobi centre until the customer taps the map or a GPS fix lands.
    var mapTarget by remember { mutableStateOf<MapPoint?>(null) }
    var mapZoom by remember { mutableStateOf(13.0) }

    fun applyFix(fixLat: Double, fixLng: Double) {
        lat = fixLat
        lng = fixLng
        locationError = null
        mapTarget = MapPoint(fixLat, fixLng)
        mapZoom = 16.0
    }

    fun capture() {
        val client = LocationServices.getFusedLocationProviderClient(context)
        client.getCurrentLocation(Priority.PRIORITY_HIGH_ACCURACY, null)
            .addOnSuccessListener { fix ->
                if (fix != null) {
                    applyFix(fix.latitude, fix.longitude)
                } else {
                    // getCurrentLocation often returns null indoors or on a cold start.
                    client.lastLocation
                        .addOnSuccessListener { last ->
                            if (last != null) applyFix(last.latitude, last.longitude)
                            else locationError = "No GPS fix yet. Tap the map to drop the pin instead."
                        }
                        .addOnFailureListener {
                            locationError = "No GPS fix yet. Tap the map to drop the pin instead."
                        }
                }
            }
            .addOnFailureListener { locationError = "Could not read the GPS position. Tap the map instead." }
    }

    val permissionLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { granted ->
        if (granted) capture() else locationError = "Location permission is needed to pin your home."
    }

    LaunchedEffect(Unit) { viewModel.loadLocations(selectDefault = false) }

    Scaffold(
        topBar = { ScreenTopBar("Delivery pins") }
    ) { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(16.dp)
        ) {
            ErrorBanner(viewModel.message) { viewModel.clearMessage() }
            viewModel.locations.forEach { location ->
                SectionCard(Modifier.padding(vertical = 6.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.LocationOn, contentDescription = null)
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(location.alias, fontWeight = FontWeight.Bold)
                            Text(
                                "${"%.4f".format(location.lat)}, ${"%.4f".format(location.lng)}",
                                fontSize = 12.sp,
                                color = MaterialTheme.colorScheme.onSurfaceVariant
                            )
                            location.landmarkNote?.let {
                                Text(it, fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        }
                        if (location.isDefault) StatusChip("Default")
                        IconButton(onClick = { viewModel.removeLocation(location.id) }) {
                            Icon(Icons.Rounded.Delete, contentDescription = "Remove pin")
                        }
                    }
                }
            }
            Spacer(Modifier.height(12.dp))
            PrimaryButton("Add a pin", onClick = { addOpen = true })
        }
    }

    if (addOpen) {
        val pinLat = lat
        val pinLng = lng
        AlertDialog(
            onDismissRequest = { addOpen = false },
            title = { Text("Pin your home") },
            text = {
                Column(Modifier.verticalScroll(rememberScrollState())) {
                    LabeledField("Alias", alias, { alias = it }, placeholder = "Albert's House")
                    Spacer(Modifier.height(10.dp))
                    Text(
                        "Tap the map to place your pin, or pull your GPS position.",
                        fontSize = 12.sp,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                    Spacer(Modifier.height(8.dp))
                    MajiMap(
                        modifier = Modifier
                            .fillMaxWidth()
                            .height(240.dp),
                        cameraTarget = mapTarget,
                        cameraZoom = mapZoom,
                        markers = if (pinLat != null && pinLng != null) {
                            listOf(
                                MapMarker(
                                    MapPoint(pinLat, pinLng),
                                    alias.ifBlank { "Home pin" }
                                )
                            )
                        } else {
                            emptyList()
                        },
                        onMapClick = { point ->
                            lat = point.lat
                            lng = point.lng
                            locationError = null
                        }
                    )
                    Spacer(Modifier.height(8.dp))
                    if (pinLat != null && pinLng != null) {
                        Text(
                            "Pin captured: ${"%.5f".format(pinLat)}, ${"%.5f".format(pinLng)}",
                            fontSize = 12.sp,
                            color = MaterialTheme.colorScheme.primary
                        )
                    }
                    locationError?.let {
                        Text(it, fontSize = 12.sp, color = MaterialTheme.colorScheme.error)
                    }
                    Spacer(Modifier.height(8.dp))
                    OutlineAction("Use my current location", onClick = {
                        permissionLauncher.launch(Manifest.permission.ACCESS_FINE_LOCATION)
                    })
                    Spacer(Modifier.height(8.dp))
                    LabeledField("Landmark note (optional)", note, { note = it })
                }
            },
            confirmButton = {
                TextButton(onClick = {
                    when {
                        alias.isBlank() ->
                            locationError = "Give this pin a name, for example \"Home\"."
                        lat == null || lng == null ->
                            locationError = "Tap the map to place your pin first."
                        else -> {
                            viewModel.addLocation(alias, lat!!, lng!!, note)
                            addOpen = false
                            alias = ""
                            note = ""
                            lat = null
                            lng = null
                            locationError = null
                        }
                    }
                }) { Text("Save pin") }
            },
            dismissButton = {
                TextButton(onClick = { addOpen = false }) { Text("Cancel") }
            }
        )
    }
}

@Composable
fun AccountScreen(
    viewModel: CustomerViewModel,
    onChangePin: (String) -> Unit,
    onOpenLocations: () -> Unit,
    onSwitchMode: () -> Unit,
    onLoggedOut: () -> Unit
) {
    val user = SessionStore.user()
    var biometric by remember { mutableStateOf(SessionStore.biometricGate) }
    var pinOpen by remember { mutableStateOf(false) }
    var newPin by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var devicesOpen by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) { viewModel.loadDevices() }

    Scaffold(
        topBar = {
            ScreenTopBar(
                "Account",
                trailing = {
                    Icon(
                        Icons.Rounded.Settings,
                        contentDescription = "Settings",
                        tint = MaterialTheme.colorScheme.onSurface
                    )
                }
            )
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
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(
                        Modifier
                            .size(48.dp)
                            .background(
                                MaterialTheme.colorScheme.primary.copy(alpha = .16f),
                                CircleShape
                            ),
                        contentAlignment = Alignment.Center
                    ) {
                        Icon(
                            Icons.Rounded.Person,
                            contentDescription = null,
                            tint = MaterialTheme.colorScheme.primary,
                            modifier = Modifier.size(28.dp)
                        )
                    }
                    Spacer(Modifier.width(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(
                            "${user?.firstName ?: ""} ${user?.lastName ?: ""}".trim()
                                .ifBlank { "MajiLink customer" },
                            fontWeight = FontWeight.Bold,
                            fontSize = 18.sp
                        )
                        Text(user?.phone ?: "", fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        user?.email?.let {
                            Text(it, fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                    }
                    Icon(
                        Icons.Rounded.ChevronRight,
                        contentDescription = null,
                        tint = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
            }

            Spacer(Modifier.height(12.dp))
            SectionCard {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(
                        Icons.Rounded.VerifiedUser,
                        contentDescription = null,
                        tint = MaterialTheme.colorScheme.primary,
                        modifier = Modifier.size(20.dp)
                    )
                    Spacer(Modifier.width(8.dp))
                    Text("Security", fontWeight = FontWeight.Bold)
                }
                Spacer(Modifier.height(10.dp))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("Fingerprint unlock")
                        Text(
                            "Uses the Android Keystore. Your fingerprint never leaves the phone.",
                            fontSize = 12.sp,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                    Switch(
                        checked = biometric,
                        onCheckedChange = {
                            biometric = it
                            SessionStore.biometricGate = it
                            viewModel.setBiometricFlag(it)
                        },
                        colors = SwitchDefaults.colors(
                            checkedTrackColor = MaterialTheme.colorScheme.primary,
                            checkedThumbColor = MaterialTheme.colorScheme.onPrimary
                        )
                    )
                }
                Spacer(Modifier.height(10.dp))
                OutlineAction(
                    "Change PIN",
                    icon = Icons.Rounded.Fingerprint,
                    onClick = { pinOpen = true },
                    modifier = Modifier.fillMaxWidth()
                )
                Spacer(Modifier.height(8.dp))
                SettingRow(
                    icon = Icons.Rounded.Devices,
                    title = "Active devices (${viewModel.devices.size})",
                    subtitle = "Phones and tablets signed in to this account",
                    onClick = { devicesOpen = true }
                )
            }

            Spacer(Modifier.height(12.dp))
            SectionCard {
                SettingRow(
                    icon = Icons.Rounded.LocationOn,
                    title = "Delivery pins",
                    subtitle = "Places you can order to. Add one on the map or from your GPS position.",
                    onClick = onOpenLocations
                )
            }

            Spacer(Modifier.height(12.dp))
            SectionCard {
                SettingRow(
                    icon = Icons.Rounded.LightMode,
                    title = "Mode",
                    subtitle = if (SessionStore.hasVendorMode) "Switch to your vendor dashboard."
                    else "Become a vendor from the web portal to unlock vendor mode.",
                    onClick = if (SessionStore.hasVendorMode) onSwitchMode else null
                )
            }

            Spacer(Modifier.height(12.dp))
            SectionCard {
                SettingRow(
                    icon = Icons.Rounded.SupportAgent,
                    title = "Help",
                    subtitle = "Call +254 700 000 001 or email hello@majilink.co.ke"
                )
            }

            Spacer(Modifier.height(16.dp))
            OutlineAction("Sign out", danger = true, icon = Icons.AutoMirrored.Rounded.Logout, onClick = {
                viewModel.logout { onLoggedOut() }
            }, modifier = Modifier.fillMaxWidth())
        }
    }

    if (pinOpen) {
        AlertDialog(
            onDismissRequest = { pinOpen = false },
            title = { Text("Change your PIN") },
            text = {
                Column {
                    LabeledField(
                        "New 6-digit PIN",
                        newPin,
                        { newPin = it.filter(Char::isDigit).take(6) },
                        keyboardType = KeyboardType.NumberPassword,
                        isPassword = true
                    )
                    Spacer(Modifier.height(8.dp))
                    LabeledField(
                        "Current password",
                        password,
                        { password = it },
                        isPassword = true
                    )
                    Text(
                        "Your password confirms the change.",
                        fontSize = 12.sp,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
            },
            confirmButton = {
                TextButton(onClick = {
                    onChangePin(newPin)
                    pinOpen = false
                }) { Text("Save") }
            },
            dismissButton = {
                TextButton(onClick = { pinOpen = false }) { Text("Cancel") }
            }
        )
    }

    if (devicesOpen) {
        AlertDialog(
            onDismissRequest = { devicesOpen = false },
            title = { Text("Active devices") },
            text = {
                Column {
                    if (viewModel.devices.isEmpty()) {
                        Text("No devices on record.")
                    }
                    viewModel.devices.forEach { device ->
                        Column(Modifier.padding(vertical = 6.dp)) {
                            Text(device.deviceUid ?: "Device", fontWeight = FontWeight.SemiBold)
                            Text(
                                if (device.pinSet) "PIN set" else "No PIN",
                                fontSize = 12.sp,
                                color = MaterialTheme.colorScheme.onSurfaceVariant
                            )
                        }
                    }
                }
            },
            confirmButton = {
                TextButton(onClick = { devicesOpen = false }) { Text("Close") }
            }
        )
    }
}

/** Icon-in-a-tile row used by the account screen's setting cards. */
@Composable
private fun SettingRow(
    icon: ImageVector,
    title: String,
    subtitle: String,
    onClick: (() -> Unit)? = null
) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        modifier = Modifier
            .fillMaxWidth()
            .then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier)
            .padding(vertical = 4.dp)
    ) {
        Box(
            Modifier
                .size(40.dp)
                .background(MaterialTheme.colorScheme.primary.copy(alpha = .12f), RoundedCornerShape(12.dp)),
            contentAlignment = Alignment.Center
        ) {
            Icon(
                icon,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.primary,
                modifier = Modifier.size(20.dp)
            )
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(title, fontWeight = FontWeight.SemiBold)
            Text(subtitle, fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        if (onClick != null) {
            Icon(
                Icons.Rounded.ChevronRight,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.onSurfaceVariant
            )
        }
    }
}
