package com.statum.nativecontacts

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.provider.ContactsContract
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeError
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import android.util.Log
import android.net.Uri

object ContactsFunctions {
    private const val DEFAULT_LIMIT = 20

    class CheckPermission(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val granted = ContextCompat.checkSelfPermission(
                context,
                Manifest.permission.READ_CONTACTS
            ) == PackageManager.PERMISSION_GRANTED

            return mapOf(
                "granted" to granted
            )
        }
    }

    class RequestPermission(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            if (ContextCompat.checkSelfPermission(
                    activity,
                    Manifest.permission.READ_CONTACTS
                ) == PackageManager.PERMISSION_GRANTED
            ) {
                return mapOf(
                    "requested" to false,
                    "granted" to true
                )
            }

            activity.runOnUiThread {
                ActivityCompat.requestPermissions(
                    activity,
                    arrayOf(Manifest.permission.READ_CONTACTS),
                    60142
                )
            }

            return mapOf(
                "requested" to true,
                "granted" to false
            )
        }
    }

    class Search(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val query = (parameters["query"] as? String).orEmpty().trim()
            val requestedLimit = (parameters["limit"] as? Number)?.toInt() ?: DEFAULT_LIMIT
            val limit = requestedLimit.coerceIn(1, 100)

            Log.i("NativeContacts", "Searching contacts with query: '$query' (limit: $limit)")

            if (ContextCompat.checkSelfPermission(context, Manifest.permission.READ_CONTACTS) != PackageManager.PERMISSION_GRANTED) {
                Log.e("NativeContacts", "Permission READ_CONTACTS not granted")
                throw BridgeError.PermissionRequired("Contacts permission is required.")
            }

            val contacts = mutableListOf<Map<String, Any?>>()
            val projection = arrayOf(
                ContactsContract.CommonDataKinds.Phone.CONTACT_ID,
                ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME,
                ContactsContract.CommonDataKinds.Phone.NUMBER,
                ContactsContract.CommonDataKinds.Phone.TYPE,
                ContactsContract.CommonDataKinds.Phone.LABEL
            )

            // If query is present, use the specialized FILTER_URI which handles name/number matching correctly
            // (including normalization of spaces/dashes in phone numbers).
            val uri = if (query.isNotEmpty()) {
                Uri.withAppendedPath(
                    ContactsContract.CommonDataKinds.Phone.CONTENT_FILTER_URI,
                    Uri.encode(query)
                )
            } else {
                ContactsContract.CommonDataKinds.Phone.CONTENT_URI
            }

            val sortOrder = "${ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME} COLLATE NOCASE ASC"

            try {
                context.contentResolver.query(uri, projection, null, null, sortOrder)?.use { cursor ->
                    val nameIndex = cursor.getColumnIndex(ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME)
                    val phoneIndex = cursor.getColumnIndex(ContactsContract.CommonDataKinds.Phone.NUMBER)
                    val typeIndex = cursor.getColumnIndex(ContactsContract.CommonDataKinds.Phone.TYPE)
                    val labelIndex = cursor.getColumnIndex(ContactsContract.CommonDataKinds.Phone.LABEL)

                    while (cursor.moveToNext() && contacts.size < limit) {
                        val name = if (nameIndex != -1) cursor.getString(nameIndex) else null
                        val phone = if (phoneIndex != -1) cursor.getString(phoneIndex) else null
                        val type = if (typeIndex != -1) cursor.getInt(typeIndex) else -1
                        val customLabel = if (labelIndex != -1) cursor.getString(labelIndex) else null

                        if (phone.isNullOrBlank()) continue

                        // Resolve the label (e.g. "Mobile", "Home", or custom)
                        val label = if (type == ContactsContract.CommonDataKinds.Phone.TYPE_CUSTOM) {
                            customLabel ?: "Other"
                        } else if (type != -1) {
                            ContactsContract.CommonDataKinds.Phone.getTypeLabel(context.resources, type, customLabel).toString()
                        } else {
                            null
                        }

                        contacts.add(
                            mapOf(
                                "name" to (name ?: phone),
                                "phone" to phone,
                                "label" to label
                            )
                        )
                    }
                }
            } catch (e: Exception) {
                Log.e("NativeContacts", "Error querying contacts", e)
                throw BridgeError.UnknownError("Error querying contacts: ${e.message}")
            }

            Log.i("NativeContacts", "Found ${contacts.size} contacts")

            return mapOf(
                "contacts" to contacts,
                "count" to contacts.size
            )
        }
    }
}
