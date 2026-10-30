package ke.co.majilink.data.local

import androidx.room.Dao
import androidx.room.Database
import androidx.room.Entity
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.PrimaryKey
import androidx.room.Query
import androidx.room.RoomDatabase
import androidx.room.Transaction
import kotlinx.coroutines.flow.Flow

@Entity(tableName = "cached_vendors")
data class CachedVendor(
    @PrimaryKey val id: Long,
    val businessName: String,
    val waterSource: String,
    val pricePer20l: String,
    val avgRating: Double,
    val distanceKm: Double,
    val etaMinutes: Int?,
    val lat: Double,
    val lng: Double
)

@Entity(tableName = "cached_orders")
data class CachedOrder(
    @PrimaryKey val id: Long,
    val orderNo: String,
    val status: String,
    val total: String,
    val alias: String?,
    val createdAt: String,
    val vendorName: String
)

@Entity(tableName = "cached_locations")
data class CachedLocation(
    @PrimaryKey val id: Long,
    val alias: String,
    val lat: Double,
    val lng: Double,
    val isDefault: Boolean
)

@Dao
interface MajiDao {

    @Query("SELECT * FROM cached_vendors ORDER BY distanceKm ASC")
    fun vendors(): Flow<List<CachedVendor>>

    @Query("SELECT * FROM cached_orders ORDER BY id DESC")
    fun orders(): Flow<List<CachedOrder>>

    @Query("SELECT * FROM cached_locations ORDER BY isDefault DESC, id ASC")
    fun locations(): Flow<List<CachedLocation>>

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insertVendors(vendors: List<CachedVendor>)

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insertOrders(orders: List<CachedOrder>)

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insertLocations(locations: List<CachedLocation>)

    @Query("DELETE FROM cached_vendors")
    suspend fun clearVendors()

    @Query("DELETE FROM cached_orders")
    suspend fun clearOrders()

    @Query("DELETE FROM cached_locations")
    suspend fun clearLocations()

    @Transaction
    suspend fun replaceVendors(vendors: List<CachedVendor>) {
        clearVendors()
        insertVendors(vendors)
    }

    @Transaction
    suspend fun replaceOrders(orders: List<CachedOrder>) {
        clearOrders()
        insertOrders(orders)
    }

    @Transaction
    suspend fun replaceLocations(locations: List<CachedLocation>) {
        clearLocations()
        insertLocations(locations)
    }
}

@Database(
    entities = [CachedVendor::class, CachedOrder::class, CachedLocation::class],
    version = 1,
    exportSchema = false
)
abstract class MajiDatabase : RoomDatabase() {
    abstract fun dao(): MajiDao

    companion object {
        @Volatile
        private var instance: MajiDatabase? = null

        fun get(context: android.content.Context): MajiDatabase =
            instance ?: synchronized(this) {
                instance ?: androidx.room.Room.databaseBuilder(
                    context.applicationContext,
                    MajiDatabase::class.java,
                    "majilink.db"
                ).fallbackToDestructiveMigration().build().also { instance = it }
            }
    }
}
