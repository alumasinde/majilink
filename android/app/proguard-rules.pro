# MajiLink release rules
-keep class ke.co.majilink.data.api.** { *; }
-keepattributes Signature, *Annotation*
-keep class com.google.gson.reflect.TypeToken { *; }
-keep class * extends com.google.gson.TypeAdapter
-dontwarn okhttp3.**
-dontwarn retrofit2.**
