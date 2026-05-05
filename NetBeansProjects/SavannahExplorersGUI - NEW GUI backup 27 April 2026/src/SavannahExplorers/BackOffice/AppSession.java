package SavannahExplorers.BackOffice;

/**
 * Holds the state of the currently logged-in user for the duration of the session.
 * Populated by LoginDialog on successful authentication.
 * Read-only after login — do not modify from other classes.
 */
public class AppSession {
    public static int     userId          = 0;
    public static String  fullName        = "";
    public static String  codiceCartella  = "";
    public static int     agentId         = 0;
    public static boolean canSelectAgent  = false;
    public static ApiClient api           = null;

    /** Returns true if a valid login session exists. */
    public static boolean isLoggedIn() {
        return userId > 0 && !codiceCartella.isEmpty();
    }
}
