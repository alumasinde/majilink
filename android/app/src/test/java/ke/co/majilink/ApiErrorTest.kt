package ke.co.majilink

import java.io.IOException
import ke.co.majilink.data.api.ApiClient
import ke.co.majilink.data.api.ApiException
import ke.co.majilink.data.api.Envelope
import ke.co.majilink.data.api.OrderDto
import kotlinx.coroutines.runBlocking
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import retrofit2.HttpException
import retrofit2.Response

/**
 * Every screen depends on ApiClient.safe turning failures into an ApiException
 * with a usable message, so the mapping is pinned here rather than discovered in
 * production.
 */
class ApiErrorTest {

    private val json = "application/json".toMediaType()

    private fun httpError(code: Int, body: String?): HttpException =
        HttpException(Response.error<Any>(code, (body ?: "").toResponseBody(json)))

    private fun failure(block: suspend () -> Envelope<OrderDto>): ApiException =
        runBlocking { ApiClient.safe(block).exceptionOrNull() as ApiException }

    @Test
    fun `a successful call unwraps the data envelope`() = runBlocking {
        val result = ApiClient.safe { Envelope(data = OrderDto(id = 5, orderNo = "ML-100005")) }

        assertTrue(result.isSuccess)
        assertEquals("ML-100005", result.getOrNull()!!.orderNo)
    }

    @Test
    fun `a null data body is an error rather than a silent success`() {
        val error = failure { Envelope() }

        assertEquals(500, error.status)
        assertEquals("empty_body", error.code)
        assertTrue(error.message.isNotBlank())
    }

    @Test
    fun `a validation failure keeps the field errors the form needs`() {
        val body = """
            {"error":{"code":"validation_error","message":"The given data was invalid.",
             "fields":{"quantity":"Quantity must be between 1 and 20 jerrycans."}}}
        """.trimIndent()

        val error = failure { throw httpError(422, body) }

        assertEquals(422, error.status)
        assertEquals("validation_error", error.code)
        assertEquals("The given data was invalid.", error.message)
        assertEquals("Quantity must be between 1 and 20 jerrycans.", error.fields["quantity"])
    }

    @Test
    fun `an error response without a body still yields a usable message`() {
        val error = failure { throw httpError(500, null) }

        assertEquals(500, error.status)
        assertNull(error.code)
        assertEquals("Request failed (500).", error.message)
        assertTrue(error.fields.isEmpty())
    }

    @Test
    fun `a non json error body does not crash the parser`() {
        val error = failure { throw httpError(502, "<html>Bad gateway</html>") }

        assertEquals(502, error.status)
        assertEquals("Request failed (502).", error.message)
    }

    @Test
    fun `a connection failure is reported as a network error`() {
        val error = failure { throw IOException("timeout") }

        assertEquals(0, error.status)
        assertEquals("network", error.code)
        assertTrue(error.message.contains("No connection"))
    }

    @Test
    fun `an unexpected throwable is wrapped instead of escaping`() {
        val error = failure { throw IllegalStateException("boom") }

        assertEquals(500, error.status)
        assertEquals("unexpected", error.code)
        // The raw throwable text must not reach the banner the customer reads.
        assertEquals("Something went wrong. Please try again.", error.message)
    }

    @Test
    fun `the update required code survives the mapping so the app can react`() {
        val body = """{"error":{"code":"app_update_required","message":"Update MajiLink to continue."}}"""

        val error = failure { throw httpError(426, body) }

        assertEquals(426, error.status)
        assertEquals("app_update_required", error.code)
    }
}
