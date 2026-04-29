package org.openstatic;

import org.java_websocket.WebSocket;
import org.java_websocket.handshake.ClientHandshake;
import org.java_websocket.server.WebSocketServer;
import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.lang.reflect.Array;
import java.net.InetSocketAddress;

public final class ApiWebSocketServer extends WebSocketServer {

    @FunctionalInterface
    public interface CommandHandler {
        /**
         * Handle a command received from a websocket client.
         *
         * @param command the value of the "command" field
         * @param payload the full parsed JSON payload (never null)
         */
        void handle(String command, JSONObject payload);
    }

    private volatile CommandHandler commandHandler;

    public ApiWebSocketServer(InetSocketAddress bindAddress) {
        super(bindAddress);
        setReuseAddr(true);
    }

    public void setCommandHandler(CommandHandler commandHandler) {
        this.commandHandler = commandHandler;
    }

    @Override
    public void onOpen(WebSocket connection, ClientHandshake handshake) {
    }

    @Override
    public void onClose(WebSocket connection, int code, String reason, boolean remote) {
    }

    @Override
    public void onMessage(WebSocket connection, String message) {
        if (message == null) {
            return;
        }

        JSONObject payload;
        try {
            payload = new JSONObject(message);
        } catch (JSONException jsonException) {
            return;
        }

        String command = payload.optString("command", null);
        if (command == null || command.isEmpty()) {
            return;
        }

        CommandHandler handler = this.commandHandler;
        if (handler != null) {
            try {
                handler.handle(command, payload);
            } catch (Exception handlerException) {
                System.err.println("websocket command handler error: " + handlerException.getMessage());
            }
        }
    }

    @Override
    public void onError(WebSocket connection, Exception exception) {
        if (exception != null) {
            String message = exception.getMessage();
            if (message == null) {
                message = exception.getClass().getSimpleName();
            }
            System.err.println("websocket api error: " + message);
        }
    }

    @Override
    public void onStart() {
    }

    public void publishEvent(String event) {
        publishEvent(event, new String[0]);
    }

    public void publishEvent(String event, String... keyValuePairs) {
        if (event == null || event.trim().isEmpty()) {
            return;
        }

        if (keyValuePairs == null) {
            keyValuePairs = new String[0];
        }

        if ((keyValuePairs.length % 2) != 0) {
            throw new IllegalArgumentException("event key/value pairs must be even");
        }

        StringBuilder json = new StringBuilder();
        json.append("{\"event\":\"").append(escapeJson(event)).append("\"");
        for (int i = 0; i < keyValuePairs.length; i += 2) {
            String key = keyValuePairs[i];
            String value = keyValuePairs[i + 1];
            if (key == null || key.trim().isEmpty()) {
                continue;
            }
            json.append(",\"").append(escapeJson(key)).append("\":\"")
                    .append(escapeJson(value == null ? "" : value))
                    .append("\"");
        }
        json.append("}");

        broadcast(json.toString());
    }

    public void publishEvent(String event, Object... keyValuePairs) {
        if (event == null || event.trim().isEmpty()) {
            return;
        }

        if (keyValuePairs == null) {
            keyValuePairs = new Object[0];
        }

        if ((keyValuePairs.length % 2) != 0) {
            throw new IllegalArgumentException("event key/value pairs must be even");
        }

        StringBuilder json = new StringBuilder();
        json.append("{\"event\":\"").append(escapeJson(event)).append("\"");
        for (int i = 0; i < keyValuePairs.length; i += 2) {
            Object keyObj = keyValuePairs[i];
            Object valueObj = keyValuePairs[i + 1];
            if (keyObj == null) {
                continue;
            }
            String key = keyObj.toString();
            if (key.trim().isEmpty()) {
                continue;
            }
            json.append(",\"").append(escapeJson(key)).append("\":");
            appendJsonValue(json, valueObj);
        }
        json.append("}");

        broadcast(json.toString());
    }

    private static void appendJsonValue(StringBuilder json, Object valueObj) {
        if (valueObj == null) {
            json.append("null");
            return;
        }

        if (valueObj instanceof JSONObject || valueObj instanceof JSONArray) {
            json.append(valueObj.toString());
            return;
        }

        if (valueObj instanceof Number || valueObj instanceof Boolean) {
            json.append(valueObj);
            return;
        }

        if (valueObj instanceof Iterable<?>) {
            json.append("[");
            boolean first = true;
            for (Object item : (Iterable<?>) valueObj) {
                if (!first) {
                    json.append(",");
                }
                appendJsonValue(json, item);
                first = false;
            }
            json.append("]");
            return;
        }

        if (valueObj.getClass().isArray()) {
            json.append("[");
            int length = Array.getLength(valueObj);
            for (int i = 0; i < length; i++) {
                if (i > 0) {
                    json.append(",");
                }
                appendJsonValue(json, Array.get(valueObj, i));
            }
            json.append("]");
            return;
        }

        String value = valueObj.toString();
        json.append("\"").append(escapeJson(value)).append("\"");
    }

    public void shutdownQuietly() {
        try {
            stop(1000);
        } catch (Exception ignored) {
        }
    }

    private static String escapeJson(String value) {
        StringBuilder escaped = new StringBuilder();
        for (int i = 0; i < value.length(); i++) {
            char ch = value.charAt(i);
            switch (ch) {
                case '"':
                    escaped.append("\\\"");
                    break;
                case '\\':
                    escaped.append("\\\\");
                    break;
                case '\b':
                    escaped.append("\\b");
                    break;
                case '\f':
                    escaped.append("\\f");
                    break;
                case '\n':
                    escaped.append("\\n");
                    break;
                case '\r':
                    escaped.append("\\r");
                    break;
                case '\t':
                    escaped.append("\\t");
                    break;
                default:
                    if (ch < 0x20) {
                        escaped.append(String.format("\\u%04x", (int) ch));
                    } else {
                        escaped.append(ch);
                    }
            }
        }
        return escaped.toString();
    }
}