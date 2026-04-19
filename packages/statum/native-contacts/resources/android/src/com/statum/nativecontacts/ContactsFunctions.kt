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

object ContactsFunctions {
    private const val DEFAULT_LIMIT = 20

    class CheckPermission(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val granted = ContextCompat.checkSelfPermission(
                context,
                Manifest.permission.READ_CONTACTS
            ) == PackageManager.PERMISSION_GRANTED

            return BridgeResponse.success(
                mapOf(
                    "granted" to granted
                )
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
                return BridgeResponse.success(
                    mapOf(
                        "requested" to false,
                        "granted" to true
                    )
                )
            }

            activity.runOnUiThread {
                ActivityCompat.requestPermissions(
                    activity,
                    arrayOf(Manifest.permission.READ_CONTACTS),
                    60142
                )
            }

            return BridgeResponse.success(
                mapOf(
                    "requested" to true,
                    "granted" to false
                )
            )
        }
    }

    class Search(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            if (ContextCompat.checkSelfPermission(
                    context,
                    Manifest.permission.READ_CONTACTS
                ) != PackageManager.PERMISSION_GRANTED
            ) {
                return BridgeResponse.error(
                    BridgeError.PermissionRequired("Contacts permission is required.")
                )
            }

            val query = (parameters["query"] as? String).orEmpty().trim()
            val requestedLimit = (parameters["limit"] as? Number)?.toInt() ?: DEFAULT_LIMIT
            val limit = requestedLimit.coerceIn(1, 50)

            val contacts = mutableListOf<Map<String, Any?>>()
            val projection = arrayOf(
                ContactsContract.CommonDataKinds.Phone.CONTACT_ID,
                ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME,
                ContactsContract.CommonDataKinds.Phone.NUMBER,
                ContactsContract.CommonDataKinds.Phone.TYPE,
                ContactsContract.CommonDataKinds.Phone.LABEL
            )

            val selection = if (query.isBlank()) {
                "${ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME} IS NOT NULL"
            } else {
                "(${ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME} LIKE ? OR ${ContactsContract.CommonDataKinds.Phone.NUMBER} LIKE ?)"
            }

            val selectionArgs = if (query.isBlank()) {
                null
            } else {
                arrayOf("%$query%", "%$query%")
            }

            context.contentResolver.query(
                ContactsContract.CommonDataKinds.Phone.CONTENT_URI,
                projection,
                selection,
                selectionArgs,
                "${ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME} COLLATE NOCASE ASC"
            )?.use { cursor ->
                val nameIndex = cursor.getColumnIndexOrThrow(ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME)
                val phoneIndex = cursor.getColumnIndexOrThrow(ContactsContract.CommonDataKinds.Phone.NUMBER)
                val labelIndex = cursor.getColumnIndexOrThrow(ContactsContract.CommonDataKinds.Phone.LABEL)

                while (cursor.moveToNext() && contacts.size < limit) {
                    val name = cursor.getString(nameIndex).orEmpty()
                    val phone = cursor.getString(phoneIndex).orEmpty()
                    val label = cursor.getString(labelIndex)

                    if (phone.isBlank()) {
                        continue
                    }

                    contacts.add(
                        mapOf(
                            "name" to name.ifBlank { phone },
                            "phone" to phone,
                            "label" to label
                        )
                    )
                }
            }

            return BridgeResponse.success(
                mapOf(
                    "contacts" to contacts,
                    "count" to contacts.size
                )
            )
        }
    }
}
