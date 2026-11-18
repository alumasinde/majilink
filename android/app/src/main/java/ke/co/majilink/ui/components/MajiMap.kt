package ke.co.majilink.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import org.maplibre.android.annotations.MarkerOptions
import org.maplibre.android.camera.CameraPosition
import org.maplibre.android.camera.CameraUpdateFactory
import org.maplibre.android.geometry.LatLng
import org.maplibre.android.maps.MapLibreMap
import org.maplibre.android.maps.MapView

private const val STYLE_URL = "https://tiles.openfreemap.org/styles/liberty"

val Nairobi = MapPoint(-1.2921, 36.8219)

data class MapPoint(val lat: Double, val lng: Double) {
    internal fun toLatLng() = LatLng(lat, lng)
}

data class MapMarker(val point: MapPoint, val title: String)

/**
 * MapLibre + OpenFreeMap: keyless vector maps. Attribution is required and stays
 * visible on the map, so do not cover the bottom corners.
 */
@Composable
fun MajiMap(
    modifier: Modifier = Modifier,
    cameraTarget: MapPoint? = null,
    cameraZoom: Double = 13.0,
    markers: List<MapMarker> = emptyList(),
    onMapClick: ((MapPoint) -> Unit)? = null
) {
    val context = LocalContext.current
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    var map by remember { mutableStateOf<MapLibreMap?>(null) }
    var styleReady by remember { mutableStateOf(false) }
    val click = rememberUpdatedState(onMapClick)

    val mapView = remember {
        MapView(context).apply { onCreate(null) }
    }

    DisposableEffect(lifecycle, mapView) {
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_START -> mapView.onStart()
                Lifecycle.Event.ON_RESUME -> mapView.onResume()
                Lifecycle.Event.ON_PAUSE -> mapView.onPause()
                Lifecycle.Event.ON_STOP -> mapView.onStop()
                else -> Unit
            }
        }
        lifecycle.addObserver(observer)
        if (lifecycle.currentState.isAtLeast(Lifecycle.State.STARTED)) mapView.onStart()
        if (lifecycle.currentState.isAtLeast(Lifecycle.State.RESUMED)) mapView.onResume()
        onDispose {
            lifecycle.removeObserver(observer)
            mapView.onPause()
            mapView.onStop()
            mapView.onDestroy()
        }
    }

    LaunchedEffect(map, styleReady, markers) {
        val ready = map ?: return@LaunchedEffect
        if (!styleReady) return@LaunchedEffect
        ready.clear()
        markers.forEach { marker ->
            ready.addMarker(MarkerOptions().position(marker.point.toLatLng()).title(marker.title))
        }
    }

    LaunchedEffect(map, styleReady, cameraTarget, cameraZoom) {
        val ready = map ?: return@LaunchedEffect
        if (!styleReady || cameraTarget == null) return@LaunchedEffect
        ready.animateCamera(CameraUpdateFactory.newLatLngZoom(cameraTarget.toLatLng(), cameraZoom))
    }

    Box(modifier) {
        AndroidView(
            factory = {
                mapView.apply {
                    getMapAsync { ready ->
                        map = ready
                        ready.uiSettings.isAttributionEnabled = true
                        ready.cameraPosition = CameraPosition.Builder()
                            .target((cameraTarget ?: Nairobi).toLatLng())
                            .zoom(cameraZoom)
                            .build()
                        ready.setStyle(STYLE_URL) { styleReady = true }
                        ready.addOnMapClickListener { latLng ->
                            click.value?.invoke(MapPoint(latLng.latitude, latLng.longitude))
                            click.value != null
                        }
                    }
                }
            },
            modifier = Modifier.matchParentSize()
        )
        Text(
            "© OpenFreeMap · © OpenMapTiles · © OpenStreetMap",
            modifier = Modifier
                .align(Alignment.BottomEnd)
                .padding(6.dp)
                .background(
                    MaterialTheme.colorScheme.surface.copy(alpha = 0.75f),
                    RoundedCornerShape(4.dp)
                )
                .padding(horizontal = 4.dp, vertical = 2.dp),
            fontSize = 9.sp,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )
    }
}
