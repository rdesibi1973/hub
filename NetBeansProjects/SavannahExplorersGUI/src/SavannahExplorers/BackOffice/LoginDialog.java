package SavannahExplorers.BackOffice;

import javax.swing.*;
import java.awt.*;
import java.io.IOException;

/**
 * Login dialog shown at startup.
 * On success populates AppSession fields so the rest of the app can use them.
 * On failure keeps looping until the user logs in or closes the app.
 */
public class LoginDialog extends JDialog {

    // Result fields — populated on successful login
    public boolean loginSuccessful = false;

    public LoginDialog(JFrame parent, ApiClient api) {
        super(parent, "Savannah Explorers — Login", true);
        setSize(360, 230);
        setLocationRelativeTo(parent);
        setResizable(false);
        setDefaultCloseOperation(JDialog.DO_NOTHING_ON_CLOSE);

        addWindowListener(new java.awt.event.WindowAdapter() {
            @Override public void windowClosing(java.awt.event.WindowEvent e) {
                // Closing the login = exit the whole app
                System.exit(0);
            }
        });

        JPanel panel = new JPanel(new GridBagLayout());
        panel.setBorder(BorderFactory.createEmptyBorder(20, 30, 20, 30));
        GridBagConstraints c = new GridBagConstraints();
        c.fill = GridBagConstraints.HORIZONTAL;
        c.insets = new Insets(5, 5, 5, 5);

        // Title
        JLabel title = new JLabel("Savannah Explorers Back Office");
        title.setFont(new Font("SansSerif", Font.BOLD, 14));
        title.setHorizontalAlignment(SwingConstants.CENTER);
        c.gridx = 0; c.gridy = 0; c.gridwidth = 2;
        panel.add(title, c);

        // Username
        c.gridwidth = 1; c.gridy = 1; c.gridx = 0; c.weightx = 0.3;
        panel.add(new JLabel("Username:"), c);
        JTextField userField = new JTextField(16);
        c.gridx = 1; c.weightx = 0.7;
        panel.add(userField, c);

        // Password
        c.gridy = 2; c.gridx = 0; c.weightx = 0.3;
        panel.add(new JLabel("Password:"), c);
        JPasswordField passField = new JPasswordField(16);
        c.gridx = 1; c.weightx = 0.7;
        panel.add(passField, c);

        // Status label
        JLabel statusLabel = new JLabel(" ");
        statusLabel.setForeground(Color.RED);
        statusLabel.setFont(new Font("SansSerif", Font.PLAIN, 11));
        statusLabel.setHorizontalAlignment(SwingConstants.CENTER);
        c.gridy = 3; c.gridx = 0; c.gridwidth = 2;
        panel.add(statusLabel, c);

        // Login button
        JButton loginBtn = new JButton("Login");
        loginBtn.setFont(new Font("SansSerif", Font.BOLD, 13));
        c.gridy = 4;
        panel.add(loginBtn, c);

        add(panel);
        getRootPane().setDefaultButton(loginBtn);

        // Action
        Runnable doLogin = () -> {
            String username = userField.getText().trim();
            String password = new String(passField.getPassword());
            if (username.isEmpty() || password.isEmpty()) {
                statusLabel.setText("Enter username and password.");
                return;
            }
            loginBtn.setEnabled(false);
            statusLabel.setForeground(Color.DARK_GRAY);
            statusLabel.setText("Connecting...");

            // Run in background thread so Swing stays responsive
            new Thread(() -> {
                try {
                    String body = "{\"username\":\"" + escapeJson(username)
                                + "\",\"password\":\"" + escapeJson(password) + "\"}";
                    String response = api.postNoKey("api_login.php", body);

                    boolean ok          = ApiClient.jsonGetBool(response, "success");
                    String message      = ApiClient.jsonGetString(response, "message");
                    String codice       = ApiClient.jsonGetString(response, "codice_cartella");
                    String fullName     = ApiClient.jsonGetString(response, "full_name");
                    int    userId       = ApiClient.jsonGetInt(response,  "user_id");
                    int    agentId      = ApiClient.jsonGetInt(response,  "agent_id");
                    boolean canSelect   = ApiClient.jsonGetBool(response, "can_select_agent");

                    SwingUtilities.invokeLater(() -> {
                        if (ok) {
                            // Populate app session
                            AppSession.userId           = userId;
                            AppSession.fullName         = fullName != null ? fullName : username;
                            AppSession.codiceCartella   = (codice != null && !codice.isEmpty()) ? codice : username;
                            AppSession.agentId          = agentId;
                            AppSession.canSelectAgent   = canSelect;
                            loginSuccessful = true;
                            dispose();
                        } else {
                            statusLabel.setForeground(Color.RED);
                            statusLabel.setText(message != null ? message : "Login failed.");
                            passField.setText("");
                            loginBtn.setEnabled(true);
                        }
                    });
                } catch (IOException ex) {
                    SwingUtilities.invokeLater(() -> {
                        statusLabel.setForeground(Color.RED);
                        statusLabel.setText("Network error: " + ex.getMessage());
                        loginBtn.setEnabled(true);
                    });
                }
            }, "login-thread").start();
        };

        loginBtn.addActionListener(e -> doLogin.run());
        // Also trigger on Enter in password field
        passField.addActionListener(e -> doLogin.run());
    }

    /** Minimal JSON string escaping for username/password. */
    private static String escapeJson(String s) {
        return s.replace("\\", "\\\\").replace("\"", "\\\"")
                .replace("\n", "\\n").replace("\r", "\\r");
    }
}
