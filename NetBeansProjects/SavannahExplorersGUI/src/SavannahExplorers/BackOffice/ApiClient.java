package SavannahExplorers.BackOffice;

import java.io.*;
import java.net.*;
import java.nio.charset.StandardCharsets;

/**
 * Minimal HTTP client for the Savannah API endpoints.
 * Uses only java.net — no external dependencies.
 * Thread-safe: each method opens its own connection.
 */
public class ApiClient {

    private final String baseUrl;
    private final String apiKey;
    private static final int CONNECT_TIMEOUT_MS = 10_000;
    private static final int READ_TIMEOUT_MS    = 20_000;

    public ApiClient(String baseUrl, String apiKey) {
        this.baseUrl = baseUrl.endsWith("/") ? baseUrl : baseUrl + "/";
        this.apiKey  = apiKey;
    }

    // -------------------------------------------------------------------------
    // POST — send a JSON body, return the response body as a String.
    // Throws IOException on network/HTTP error.
    // -------------------------------------------------------------------------
    public String post(String endpoint, String jsonBody) throws IOException {
        URL url = new URL(baseUrl + endpoint);
        HttpURLConnection conn = (HttpURLConnection) url.openConnection();
        try {
            conn.setRequestMethod("POST");
            conn.setDoOutput(true);
            conn.setConnectTimeout(CONNECT_TIMEOUT_MS);
            conn.setReadTimeout(READ_TIMEOUT_MS);
            conn.setRequestProperty("Content-Type", "application/json; charset=UTF-8");
            if (apiKey != null && !apiKey.isEmpty()) {
                conn.setRequestProperty("X-Api-Key", apiKey);
            }
            byte[] data = jsonBody.getBytes(StandardCharsets.UTF_8);
            conn.setRequestProperty("Content-Length", String.valueOf(data.length));
            try (OutputStream os = conn.getOutputStream()) {
                os.write(data);
            }
            return readResponse(conn);
        } finally {
            conn.disconnect();
        }
    }

    // -------------------------------------------------------------------------
    // Response wrapper carrying both the HTTP status code and the body.
    // -------------------------------------------------------------------------
    public static class ApiResponse {
        public final int code;
        public final String body;
        public ApiResponse(int code, String body) { this.code = code; this.body = body; }
        public boolean isOk() { return code >= 200 && code < 300; }
    }

    // -------------------------------------------------------------------------
    // POST returning HTTP status + body. Throws IOException only on a true
    // network failure (no connection / timeout). A 4xx/5xx is returned as a
    // populated ApiResponse so the caller can show the actual HTTP code.
    // -------------------------------------------------------------------------
    public ApiResponse postWithStatus(String endpoint, String jsonBody) throws IOException {
        URL url = new URL(baseUrl + endpoint);
        HttpURLConnection conn = (HttpURLConnection) url.openConnection();
        try {
            conn.setRequestMethod("POST");
            conn.setDoOutput(true);
            conn.setConnectTimeout(CONNECT_TIMEOUT_MS);
            conn.setReadTimeout(READ_TIMEOUT_MS);
            conn.setRequestProperty("Content-Type", "application/json; charset=UTF-8");
            if (apiKey != null && !apiKey.isEmpty()) {
                conn.setRequestProperty("X-Api-Key", apiKey);
            }
            byte[] data = jsonBody.getBytes(StandardCharsets.UTF_8);
            conn.setRequestProperty("Content-Length", String.valueOf(data.length));
            try (OutputStream os = conn.getOutputStream()) {
                os.write(data);
            }
            int code = conn.getResponseCode();
            String body = readBody(conn, code);
            return new ApiResponse(code, body);
        } finally {
            conn.disconnect();
        }
    }

    // Read the body given an already-known status code (error stream for >=400).
    private static String readBody(HttpURLConnection conn, int code) throws IOException {
        InputStream is = (code >= 400) ? conn.getErrorStream() : conn.getInputStream();
        if (is == null) return "";
        try (BufferedReader br = new BufferedReader(new InputStreamReader(is, StandardCharsets.UTF_8))) {
            StringBuilder sb = new StringBuilder();
            String line;
            while ((line = br.readLine()) != null) sb.append(line);
            return sb.toString();
        }
    }

    // -------------------------------------------------------------------------
    // POST — without API key (used for api_login which authenticates differently)
    // -------------------------------------------------------------------------
    public String postNoKey(String endpoint, String jsonBody) throws IOException {
        String savedKey = this.apiKey;
        // Temporarily use a client without key by constructing the request manually
        URL url = new URL(baseUrl + endpoint);
        HttpURLConnection conn = (HttpURLConnection) url.openConnection();
        try {
            conn.setRequestMethod("POST");
            conn.setDoOutput(true);
            conn.setConnectTimeout(CONNECT_TIMEOUT_MS);
            conn.setReadTimeout(READ_TIMEOUT_MS);
            conn.setRequestProperty("Content-Type", "application/json; charset=UTF-8");
            // No X-Api-Key header
            byte[] data = jsonBody.getBytes(StandardCharsets.UTF_8);
            conn.setRequestProperty("Content-Length", String.valueOf(data.length));
            try (OutputStream os = conn.getOutputStream()) {
                os.write(data);
            }
            return readResponse(conn);
        } finally {
            conn.disconnect();
        }
    }

    // -------------------------------------------------------------------------
    // GET — append queryString (e.g. "name=foo&exclude_id=3"), return body.
    // -------------------------------------------------------------------------
    public String get(String endpoint, String queryString) throws IOException {
        String fullUrl = baseUrl + endpoint;
        if (queryString != null && !queryString.isEmpty()) {
            fullUrl += "?" + queryString;
        }
        URL url = new URL(fullUrl);
        HttpURLConnection conn = (HttpURLConnection) url.openConnection();
        try {
            conn.setRequestMethod("GET");
            conn.setConnectTimeout(CONNECT_TIMEOUT_MS);
            conn.setReadTimeout(READ_TIMEOUT_MS);
            if (apiKey != null && !apiKey.isEmpty()) {
                conn.setRequestProperty("X-Api-Key", apiKey);
            }
            return readResponse(conn);
        } finally {
            conn.disconnect();
        }
    }

    // -------------------------------------------------------------------------
    // GET that FAILS LOUDLY — throws IOException on any non-2xx status.
    //
    // Unlike get(), which returns the 4xx/5xx error body as if it were a normal
    // response (so callers silently receive an HTML/PHP error page or an empty
    // string), this variant treats a >=400 status as a real failure. Use it for
    // list loads (agencies/agents) so a transient 401/403/500 triggers the
    // caller's catch/retry path instead of leaving the LOV silently empty.
    // -------------------------------------------------------------------------
    public String getOrThrow(String endpoint, String queryString) throws IOException {
        String fullUrl = baseUrl + endpoint;
        if (queryString != null && !queryString.isEmpty()) {
            fullUrl += "?" + queryString;
        }
        URL url = new URL(fullUrl);
        HttpURLConnection conn = (HttpURLConnection) url.openConnection();
        try {
            conn.setRequestMethod("GET");
            conn.setConnectTimeout(CONNECT_TIMEOUT_MS);
            conn.setReadTimeout(READ_TIMEOUT_MS);
            if (apiKey != null && !apiKey.isEmpty()) {
                conn.setRequestProperty("X-Api-Key", apiKey);
            }
            int code = conn.getResponseCode();
            String body = readBody(conn, code);
            if (code < 200 || code >= 300) {
                String snippet = body == null ? "" : body.trim().replaceAll("\\s+", " ");
                if (snippet.length() > 200) snippet = snippet.substring(0, 200) + "…";
                throw new IOException("HTTP " + code + (snippet.isEmpty() ? "" : " — " + snippet));
            }
            return body;
        } finally {
            conn.disconnect();
        }
    }

    // -------------------------------------------------------------------------
    // Read response body from connection (handles 4xx/5xx error streams too).
    // -------------------------------------------------------------------------
    private static String readResponse(HttpURLConnection conn) throws IOException {
        int code = conn.getResponseCode();
        InputStream is = (code >= 400) ? conn.getErrorStream() : conn.getInputStream();
        if (is == null) return "";
        try (BufferedReader br = new BufferedReader(new InputStreamReader(is, StandardCharsets.UTF_8))) {
            StringBuilder sb = new StringBuilder();
            String line;
            while ((line = br.readLine()) != null) sb.append(line);
            return sb.toString();
        }
    }

    // -------------------------------------------------------------------------
    // Minimal JSON helpers — avoids external libraries.
    // These are intentionally simple: they handle the flat JSON structures
    // returned by our API. For nested/complex JSON use a proper library.
    // -------------------------------------------------------------------------

    /** Extract a String field from a flat JSON object. Returns null if absent. */
    public static String jsonGetString(String json, String key) {
        // Matches: "key":"value" or "key": "value"
        String pattern = "\"" + key + "\"\\s*:\\s*\"((?:[^\"\\\\]|\\\\.)*)\"";
        java.util.regex.Matcher m = java.util.regex.Pattern.compile(pattern).matcher(json);
        return m.find() ? m.group(1) : null;
    }

    /** Extract a boolean field. Returns false if absent. */
    public static boolean jsonGetBool(String json, String key) {
        String pattern = "\"" + key + "\"\\s*:\\s*(true|false)";
        java.util.regex.Matcher m = java.util.regex.Pattern.compile(pattern).matcher(json);
        return m.find() && "true".equals(m.group(1));
    }

    /** Extract an integer field. Returns 0 if absent. */
    public static int jsonGetInt(String json, String key) {
        String pattern = "\"" + key + "\"\\s*:\\s*(\\d+)";
        java.util.regex.Matcher m = java.util.regex.Pattern.compile(pattern).matcher(json);
        return m.find() ? Integer.parseInt(m.group(1)) : 0;
    }

    /**
     * Parse a JSON array of {id, nome} objects (agencies and agents).
     * Returns a 2D array: result[i][0] = id string, result[i][1] = nome string.
     */
    public static String[][] parseIdNomeArray(String json) {
        java.util.List<String[]> list = new java.util.ArrayList<>();
        // Match each object: {"id":N,"nome":"..."}  or {"id":N,"name":"..."}
        java.util.regex.Pattern p = java.util.regex.Pattern.compile(
            "\\{[^}]*\"id\"\\s*:\\s*(\\d+)[^}]*\"(?:nome|name)\"\\s*:\\s*\"([^\"]+)\"[^}]*\\}");
        java.util.regex.Matcher m = p.matcher(json);
        while (m.find()) {
            list.add(new String[]{ m.group(1), m.group(2) });
        }
        return list.toArray(new String[0][]);
    }
}
