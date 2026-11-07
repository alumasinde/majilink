package ke.co.majilink.ui.navigation

import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.EventRepeat
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.ReceiptLong
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import ke.co.majilink.data.SessionStore
import ke.co.majilink.ui.auth.AuthViewModel
import ke.co.majilink.ui.auth.LoginScreen
import ke.co.majilink.ui.auth.PinLoginScreen
import ke.co.majilink.ui.auth.RegisterScreen
import ke.co.majilink.ui.auth.WelcomeScreen
import ke.co.majilink.ui.customer.AccountScreen
import ke.co.majilink.ui.customer.CustomerHomeScreen
import ke.co.majilink.ui.customer.CustomerOrdersScreen
import ke.co.majilink.ui.customer.CustomerViewModel
import ke.co.majilink.ui.customer.LocationsScreen
import ke.co.majilink.ui.customer.ScheduleScreen
import ke.co.majilink.ui.customer.TrackingScreen
import ke.co.majilink.ui.customer.VendorOrderScreen
import ke.co.majilink.ui.theme.MajiRole
import ke.co.majilink.ui.vendor.VendorAccountScreen
import ke.co.majilink.ui.vendor.VendorHomeScreen
import ke.co.majilink.ui.vendor.VendorOrderScreen as VendorTrackingScreen
import ke.co.majilink.ui.vendor.VendorShopScreen
import ke.co.majilink.ui.vendor.VendorViewModel
import ke.co.majilink.ui.vendor.VendorWalletScreen

private data class TabItem(val route: String, val label: String, val icon: ImageVector)

@Composable
fun MajiNav(
    role: MajiRole,
    onRoleChanged: (MajiRole) -> Unit
) {
    val navController = rememberNavController()
    val authViewModel: AuthViewModel = viewModel()
    val customerViewModel: CustomerViewModel = viewModel()
    val vendorViewModel: VendorViewModel = viewModel()

    val start = when {
        !SessionStore.isLoggedIn -> "welcome"
        SessionStore.mode == "vendor" && SessionStore.hasVendorMode -> "vendorHome"
        else -> "home"
    }

    val customerTabs = listOf(
        TabItem("home", "Home", Icons.Rounded.Home),
        TabItem("orders", "Orders", Icons.Rounded.ReceiptLong),
        TabItem("schedule", "Schedule", Icons.Rounded.EventRepeat),
        TabItem("account", "Account", Icons.Rounded.Person)
    )
    val vendorTabs = listOf(
        TabItem("vendorHome", "Orders", Icons.Rounded.Storefront),
        TabItem("vendorWallet", "Wallet", Icons.Rounded.AccountBalanceWallet),
        TabItem("vendorShop", "Shop", Icons.Rounded.Storefront),
        TabItem("vendorAccount", "Account", Icons.Rounded.Person)
    )
    val tabs = if (role == MajiRole.Vendor) vendorTabs else customerTabs

    val backStackEntry by navController.currentBackStackEntryAsState()
    val currentRoute = backStackEntry?.destination?.route
    val showBar = tabs.any { it.route == currentRoute } ||
        currentRoute == "order/{vendorId}" || currentRoute == "tracking/{orderId}" ||
        currentRoute == "locations" || currentRoute == "vendorOrder/{orderId}"

    Scaffold(
        bottomBar = {
            if (showBar) {
                NavigationBar {
                    tabs.forEach { tab ->
                        NavigationBarItem(
                            selected = currentRoute == tab.route,
                            onClick = {
                                navController.navigate(tab.route) {
                                    popUpTo(navController.graph.startDestinationId) { saveState = true }
                                    launchSingleTop = true
                                    restoreState = true
                                }
                            },
                            icon = { Icon(tab.icon, contentDescription = tab.label) },
                            label = { Text(tab.label) }
                        )
                    }
                }
            }
        }
    ) { padding ->
        NavHost(
            navController = navController,
            startDestination = start,
            modifier = Modifier.padding(padding)
        ) {
            composable("welcome") {
                WelcomeScreen(
                    onRegister = { navController.navigate("register") },
                    onLogin = { navController.navigate("login") }
                )
            }
            composable("login") {
                LoginScreen(
                    viewModel = authViewModel,
                    onSignedIn = {
                        val vendor = SessionStore.mode == "vendor" && SessionStore.hasVendorMode
                        onRoleChanged(if (vendor) MajiRole.Vendor else MajiRole.Customer)
                        navController.navigate(if (vendor) "vendorHome" else "home") {
                            popUpTo("welcome") { inclusive = true }
                        }
                    },
                    onRegister = { navController.navigate("register") }
                )
            }
            composable("pin") {
                PinLoginScreen(
                    viewModel = authViewModel,
                    onSignedIn = {
                        onRoleChanged(MajiRole.Customer)
                        navController.navigate("home") { popUpTo("welcome") { inclusive = true } }
                    },
                    onUsePassword = { navController.navigate("login") }
                )
            }
            composable("register") {
                RegisterScreen(
                    viewModel = authViewModel,
                    onSignedIn = {
                        onRoleChanged(MajiRole.Customer)
                        navController.navigate("home") { popUpTo("welcome") { inclusive = true } }
                    },
                    onBack = { navController.popBackStack() }
                )
            }

            composable("home") {
                CustomerHomeScreen(
                    viewModel = customerViewModel,
                    onOpenVendor = { vendorId -> navController.navigate("order/$vendorId") },
                    onTrackOrder = { orderId -> navController.navigate("tracking/$orderId") },
                    onOpenLocations = { navController.navigate("locations") }
                )
            }
            composable(
                "order/{vendorId}",
                arguments = listOf(navArgument("vendorId") { type = NavType.LongType })
            ) { entry ->
                val vendorId = entry.arguments?.getLong("vendorId") ?: 0L
                VendorOrderScreen(
                    viewModel = customerViewModel,
                    vendorId = vendorId,
                    onPlaced = { orderId ->
                        navController.navigate("tracking/$orderId") {
                            popUpTo("order/{vendorId}") { inclusive = true }
                        }
                    },
                    onBack = { navController.popBackStack() }
                )
            }
            composable(
                "tracking/{orderId}",
                arguments = listOf(navArgument("orderId") { type = NavType.LongType })
            ) { entry ->
                val orderId = entry.arguments?.getLong("orderId") ?: 0L
                TrackingScreen(
                    viewModel = customerViewModel,
                    orderId = orderId,
                    onBack = { navController.navigate("home") { popUpTo("home") { inclusive = true } } }
                )
            }
            composable("orders") {
                CustomerOrdersScreen(
                    viewModel = customerViewModel,
                    onOpenOrder = { orderId -> navController.navigate("tracking/$orderId") },
                    onReorder = { vendorId -> navController.navigate("order/$vendorId") }
                )
            }
            composable("schedule") {
                ScheduleScreen(viewModel = customerViewModel)
            }
            composable("locations") {
                LocationsScreen(viewModel = customerViewModel)
            }
            composable("account") {
                AccountScreen(
                    viewModel = customerViewModel,
                    onChangePin = { pin -> authViewModel.setPin(pin) },
                    onOpenLocations = { navController.navigate("locations") },
                    onSwitchMode = {
                        SessionStore.mode = "vendor"
                        onRoleChanged(MajiRole.Vendor)
                        navController.navigate("vendorHome") {
                            popUpTo(navController.graph.startDestinationId) { inclusive = true }
                        }
                    },
                    onLoggedOut = {
                        onRoleChanged(MajiRole.Customer)
                        navController.navigate("welcome") {
                            popUpTo(navController.graph.startDestinationId) { inclusive = true }
                        }
                    }
                )
            }

            composable("vendorHome") {
                VendorHomeScreen(
                    viewModel = vendorViewModel,
                    onOpenOrder = { orderId -> navController.navigate("vendorOrder/$orderId") }
                )
            }
            composable(
                "vendorOrder/{orderId}",
                arguments = listOf(navArgument("orderId") { type = NavType.LongType })
            ) { entry ->
                VendorTrackingScreen(
                    viewModel = vendorViewModel,
                    orderId = entry.arguments?.getLong("orderId") ?: 0L,
                    onBack = { navController.popBackStack() }
                )
            }
            composable("vendorWallet") {
                VendorWalletScreen(viewModel = vendorViewModel)
            }
            composable("vendorShop") {
                VendorShopScreen(viewModel = vendorViewModel)
            }
            composable("vendorAccount") {
                VendorAccountScreen(
                    viewModel = vendorViewModel,
                    onSwitchMode = {
                        SessionStore.mode = "customer"
                        onRoleChanged(MajiRole.Customer)
                        navController.navigate("home") {
                            popUpTo(navController.graph.startDestinationId) { inclusive = true }
                        }
                    },
                    onLoggedOut = {
                        onRoleChanged(MajiRole.Customer)
                        navController.navigate("welcome") {
                            popUpTo(navController.graph.startDestinationId) { inclusive = true }
                        }
                    }
                )
            }
        }
    }
}
