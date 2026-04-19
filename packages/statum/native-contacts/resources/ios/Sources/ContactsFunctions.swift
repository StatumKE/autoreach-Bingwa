import Foundation

enum ContactsFunctions {
    class CheckPermission: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            return BridgeResponse.error(message: "Contacts search is only implemented on Android.")
        }
    }

    class RequestPermission: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            return BridgeResponse.error(message: "Contacts search is only implemented on Android.")
        }
    }

    class Search: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            return BridgeResponse.error(message: "Contacts search is only implemented on Android.")
        }
    }
}
