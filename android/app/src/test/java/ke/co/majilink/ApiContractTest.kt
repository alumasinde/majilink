package ke.co.majilink

import com.google.gson.reflect.TypeToken
import ke.co.majilink.data.api.ApiClient
import ke.co.majilink.data.api.ApiErrorEnvelope
import ke.co.majilink.data.api.Envelope
import ke.co.majilink.data.api.LocationDto
import ke.co.majilink.data.api.NearbyVendorsPayload
import ke.co.majilink.data.api.OrderDto
import ke.co.majilink.data.api.RecurringListPayload
import ke.co.majilink.data.api.RuntimeConfig
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The app and the API agree on snake_case JSON and nothing enforces that at
 * compile time, so this is the tripwire: real response shapes from
 * /api/v1/... parsed with the same Gson the app uses in production.
 */
class ApiContractTest {

    private fun order(json: String): OrderDto {
        val type = object : TypeToken<Envelope<OrderDto>>() {}.type
        val envelope: Envelope<OrderDto> = ApiClient.gson.fromJson(json, type)
        return requireNotNull(envelope.data)
    }

    private fun nearby(json: String): Envelope<NearbyVendorsPayload> {
        val type = object : TypeToken<Envelope<NearbyVendorsPayload>>() {}.type
        return ApiClient.gson.fromJson(json, type)
    }

    private fun config(json: String): RuntimeConfig {
        val type = object : TypeToken<Envelope<RuntimeConfig>>() {}.type
        val envelope: Envelope<RuntimeConfig> = ApiClient.gson.fromJson(json, type)
        return requireNotNull(envelope.data)
    }

    @Test
    fun `order response maps every field the tracking screen reads`() {
        val json = """
            {"data":{
              "id":7,
              "order_no":"ML-100007",
              "status":{"name":"On the way","is_final":false},
              "quantity":4,
              "unit_price":"60.00",
              "delivery_fee":"50.00",
              "total":"290.00",
              "requested_at":"2026-10-30 09:00:00",
              "accepted_at":"2026-10-30 09:01:10",
              "expires_at":null,
              "expires_in_seconds":null,
              "payment":{"method":"M-Pesa","status":"Pending","receipt":null},
              "distance_km":1.2,
              "rating":null,
              "customer":{"id":3,"first_name":"Albert","last_name":"Mwangi","phone":"0712000001"},
              "vendor":{"id":9,"business_name":"Kilimani Pure Water","phone":"0722000001","base_lat":-1.292,"base_lng":36.786},
              "delivery":{"alias":"Albert's House","lat":-1.2925,"lng":36.7875},
              "note":"Call at the gate",
              "actions":{"cancel":true,"rate":false,"call_vendor":true,"pay_mpesa":true,
                         "accept":false,"decline":false,"on_the_way":false,"delivered":false,
                         "navigate":false,"call_customer":false},
              "history":[
                {"id":1,"status":"Pending","note":"Order placed","changed_by":3,"created_at":"2026-10-30 09:00:00"},
                {"id":2,"status":"Accepted","note":null,"changed_by":5,"created_at":"2026-10-30 09:01:10"}
              ]
            }}
        """.trimIndent()

        val order = order(json)

        assertEquals(7L, order.id)
        assertEquals("ML-100007", order.orderNo)
        assertEquals("On the way", order.status.name)
        assertFalse(order.status.isFinal)
        assertEquals(4, order.quantity)
        assertEquals("60.00", order.unitPrice)
        assertEquals("290.00", order.total)
        assertEquals("M-Pesa", order.payment.method)
        assertEquals("Pending", order.payment.status)
        assertEquals("Albert's House", order.delivery.alias)
        assertEquals(-1.2925, order.delivery.lat!!, 0.0000001)
        assertEquals("Kilimani Pure Water", order.vendor.businessName)
        assertTrue(order.actions.cancel)
        assertTrue(order.actions.payMpesa)
        assertTrue(order.actions.callVendor)
        assertFalse(order.actions.delivered)
        assertEquals(2, order.history.size)
        assertEquals("Accepted", order.history[1].status)
        assertEquals("Call at the gate", order.note)
    }

    @Test
    fun `vendor nearby response maps distance eta and radius metadata`() {
        val json = """
            {"data":{"vendors":[
              {"id":9,"business_name":"Kilimani Pure Water",
               "water_source":{"id":2,"name":"Purified"},
               "price_per_20l":"60.00","avg_rating":4.5,"rating_count":12,
               "distance_km":0.8,"road_distance_km":1.08,"eta_minutes":4,
               "opens_at":null,"closes_at":null,"base_lat":-1.292,"base_lng":36.786}
            ]},
             "meta":{"radius_km":3,"max_radius_km":5,"count":1}}
        """.trimIndent()

        val envelope = nearby(json)
        val vendors = requireNotNull(envelope.data).vendors

        assertEquals(1, vendors.size)
        assertEquals("Purified", vendors[0].waterSource.name)
        assertEquals(0.8, vendors[0].distanceKm, 0.0001)
        assertEquals(4, vendors[0].etaMinutes)
        assertEquals(4.5, vendors[0].avgRating, 0.0001)
        assertEquals(5.0, envelope.meta!!.maxRadiusKm!!, 0.0001)
    }

    @Test
    fun `runtime config maps the gate maintenance and limit sections`() {
        val json = """
            {"data":{
              "app":{"min_supported_version_code":3,"min_recommended_version_code":5,
                     "latest_version_name":"1.2.0","update_url":"https://majilink.co.ke/app.apk",
                     "update_message":"Update to keep ordering.","force_update":true,
                     "allow_endpoint_override":false},
              "maintenance":{"enabled":false,"message":"Back shortly"},
              "endpoint":{"api_base_url":"https://api.majilink.co.ke/api/v1/","environment":"production"},
              "flags":{"live_tracking":true},
              "limits":{"currency":"KES","min_quantity":1,"max_quantity":20,
                        "default_radius_km":3,"max_radius_km":5,"order_expiry_minutes":5},
              "server_time":"2026-10-30T09:00:00Z"
            }}
        """.trimIndent()

        val config = config(json)

        assertEquals(3, config.app!!.minSupportedVersionCode)
        assertEquals(5, config.app.minRecommendedVersionCode)
        assertTrue(config.app.forceUpdate)
        assertEquals("1.2.0", config.app.latestVersionName)
        assertFalse(config.maintenance!!.enabled)
        assertEquals("production", config.endpoint!!.environment)
        assertEquals(true, config.flags["live_tracking"])
        assertEquals(20, config.limits!!.maxQuantity)
        assertEquals("KES", config.limits.currency)
    }

    @Test
    fun `a config without optional sections still parses`() {
        val config = config("""{"data":{"server_time":"2026-10-30T09:00:00Z"}}""")

        assertNull(config.app)
        assertNull(config.maintenance)
        assertNull(config.endpoint)
        assertTrue(config.flags.isEmpty())
        assertNull(config.limits)
    }

    @Test
    fun `unknown fields from a newer backend are ignored`() {
        // Additive backend columns must never break an installed APK.
        val json = """
            {"data":{"id":11,"order_no":"ML-100011","status":{"name":"Pending","is_final":false},
             "quantity":1,"unit_price":"60.00","delivery_fee":"50.00","total":"110.00",
             "brand_new_field":"ignore me","nested_future":{"a":1}}}
        """.trimIndent()

        val order = order(json)

        assertEquals(11L, order.id)
        assertEquals("Pending", order.status.name)
        assertFalse(order.status.isFinal)
    }

    @Test
    fun `missing optional fields fall back to safe defaults`() {
        val order = order("""{"data":{"id":1}}""")

        assertEquals("", order.orderNo)
        assertEquals("", order.status.name)
        assertFalse(order.status.isFinal)
        assertEquals(0, order.quantity)
        assertNull(order.payment.method)
        assertFalse(order.actions.cancel)
        assertTrue(order.history.isEmpty())
    }

    @Test
    fun `error envelope carries a code a message and per field errors`() {
        val json = """
            {"error":{"code":"validation_error","message":"The given data was invalid.",
             "fields":{"quantity":"Quantity must be between 1 and 20 jerrycans.",
                       "vendor_id":"That vendor does not deliver to this pin."}}}
        """.trimIndent()

        val error = ApiClient.gson.fromJson(json, ApiErrorEnvelope::class.java).error
        assertNotNull(error)

        assertEquals("validation_error", error!!.code)
        assertEquals(2, error.fields!!.size)
        assertEquals("Quantity must be between 1 and 20 jerrycans.", error.fields["quantity"])
    }

    @Test
    fun `status names used by the UI stay in sync with the backend`() {
        // These strings drive colour chips and button visibility in the app.
        val known = listOf("Pending", "Accepted", "On the way", "Delivered", "Declined", "Cancelled", "Expired")

        val order = order("""{"data":{"status":{"name":"Delivered","is_final":true}}}""")

        assertTrue(order.status.name in known)
        assertTrue(order.status.isFinal)
    }

    @Test
    fun `a tinyint 0 or 1 still parses as a boolean`() {
        // MySQL hands TINYINT(1) back as a number. The services cast it on the way out,
        // but one that forgets must not take a whole screen down with a parse error.
        val locationsType = object : TypeToken<Envelope<Map<String, List<LocationDto>>>>() {}.type
        val locations: Envelope<Map<String, List<LocationDto>>> = ApiClient.gson.fromJson(
            """{"data":{"locations":[{"id":1,"alias":"Albert's House","lat":-1.2925,
                "lng":36.7875,"landmark_note":null,"is_default":1}]}}""".trimIndent(),
            locationsType
        )
        assertTrue(requireNotNull(locations.data)["locations"]!!.single().isDefault)

        val scheduleType = object : TypeToken<Envelope<RecurringListPayload>>() {}.type
        val schedules: Envelope<RecurringListPayload> = ApiClient.gson.fromJson(
            """{"data":{"schedules":[{"id":2,"vendor_id":9,"business_name":"Kilimani Pure Water",
                "frequency":"Weekly","frequency_id":1,"quantity":3,
                "next_run_at":"2026-11-06 09:00:00","is_active":0,
                "location_alias":"Albert's House"}]}}""".trimIndent(),
            scheduleType
        )
        assertFalse(requireNotNull(schedules.data).schedules.single().isActive)
    }
}
