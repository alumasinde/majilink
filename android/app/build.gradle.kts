import java.net.URI

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
    id("com.google.devtools.ksp")
}

val productionApiBase = "https://majilink.co.ke/api/v1/"
val debugApiBase = "http://10.0.2.2:8099/api/v1/"
val apiBaseOverride = (project.findProperty("majilink.apiBase") as String?)?.trim().orEmpty()

fun isLoopback(raw: String): Boolean {
    val host = runCatching { URI(raw).host }.getOrNull()?.lowercase() ?: return true
    return host == "localhost" || host == "127.0.0.1" || host == "0.0.0.0" ||
        host == "::1" || host == "[::1]" || host.startsWith("127.")
}

fun apiBase(buildType: String): String {
    // Release builds ship to production: only a real HTTPS override is accepted.
    if (buildType == "release") {
        val usable = apiBaseOverride.startsWith("https://") && !isLoopback(apiBaseOverride)
        if (!usable) return productionApiBase
    }
    val base = apiBaseOverride.ifEmpty { if (buildType == "release") productionApiBase else debugApiBase }
    return if (base.endsWith("/")) base else "$base/"
}

android {
    namespace = "ke.co.majilink"
    compileSdk = 35

    defaultConfig {
        applicationId = "ke.co.majilink"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "1.0.0"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        vectorDrawables.useSupportLibrary = true
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            buildConfigField("String", "API_BASE", "\"${apiBase("release")}\"")
        }
        debug {
            applicationIdSuffix = ".debug"
            buildConfigField("String", "API_BASE", "\"${apiBase("debug")}\"")
        }
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    packaging {
        resources.excludes += "/META-INF/{AL2.0,LGPL2.1}"
    }
}

dependencies {
    val composeBom = platform("androidx.compose:compose-bom:2024.10.01")
    implementation(composeBom)

    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.activity:activity-compose:1.9.3")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.8.6")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.8.6")
    implementation("androidx.navigation:navigation-compose:2.8.3")

    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-extended")
    debugImplementation("androidx.compose.ui:ui-tooling")

    implementation("com.squareup.retrofit2:retrofit:2.11.0")
    implementation("com.squareup.retrofit2:converter-gson:2.11.0")
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("com.squareup.okhttp3:logging-interceptor:4.12.0")
    implementation("com.google.code.gson:gson:2.11.0")

    implementation("androidx.room:room-runtime:2.6.1")
    implementation("androidx.room:room-ktx:2.6.1")
    ksp("androidx.room:room-compiler:2.6.1")

    implementation("io.coil-kt:coil-compose:2.7.0")

    implementation("org.maplibre.gl:android-sdk:11.11.0")
    implementation("com.google.android.gms:play-services-location:21.3.0")

    implementation("androidx.biometric:biometric:1.2.0-alpha05")
    implementation("androidx.security:security-crypto:1.1.0-alpha06")

    implementation(platform("com.google.firebase:firebase-bom:33.5.1"))
    implementation("com.google.firebase:firebase-messaging")

    testImplementation("junit:junit:4.13.2")
    androidTestImplementation("androidx.test.ext:junit:1.2.1")
    androidTestImplementation("androidx.test.espresso:espresso-core:3.6.1")
}
