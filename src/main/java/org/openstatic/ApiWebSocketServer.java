package org.openstatic;

import org.java_websocket.WebSocket;
import org.java_websocket.handshake.ClientHandshake;
import org.java_websocket.server.WebSocketServer;

import java.net.InetSocketAddress;

public final class ApiWebSocketServer extends WebSocketServer {

    public ApiWebSocketServer(InetSocketAddress bindAddress) {
        super(bindAddress);
        setReuseAddr(true);
    }

    @Override
    public void onOpen(WebSocket connection, ClientHandshake handshake) {
    }

    @Override
    public void onClose(WebSocket connection, int code, String reason, boolean remote) {
    }

    @Override
    public void onMessage(WebSocket connection, String message) {
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
            if (valueObj == null) {
                json.append("null");
            } else if (valueObj instanceof Number) {
                json.append(valueObj);
            } else {
                String value = valueObj.toString();
                json.append("\"").append(escapeJson(value)).append("\"");
            }
        }
        json.append("}");

        broadcast(json.toString());
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