package SavannahExplorers.BackOffice;

import javax.swing.*;
import javax.swing.event.DocumentEvent;
import javax.swing.event.DocumentListener;
import java.awt.*;
import java.io.IOException;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.ScheduledFuture;
import java.util.concurrent.TimeUnit;

public class NewRequestDialog extends JDialog {

    private final ApiClient api;
    private final BackOfficeMain parent;

    private JTextField customerNameField;
    private JComboBox<String> channelCombo;
    private JComboBox<String> agencyCombo;
    private JComboBox<String> agentCombo;
    private JComboBox<String> sourceCombo;
    private JSpinner paxSpinner;
    private JTextArea initialRequestArea;
    private JLabel folderPreviewLabel;
    private JLabel dupWarningLabel;
    private JButton createButton;

    private String[][] agencies = new String[0][];
    private String[][] agents   = new String[0][];
    // Full lists for autocomplete reset
    private String[] allAgencyNames = new String[0];
    private String[] allAgentNames  = new String[0];
    private boolean suppressFilter  = false; // prevents autocomplete filter during init

    private static final String[] SOURCES = {
        "Form", "Email", "Agent", "iBot",
        "WhatsApp", "Social", "Safari Bookings", "Other"
    };

    private final ScheduledExecutorService scheduler = Executors.newSingleThreadScheduledExecutor();
    private ScheduledFuture<?> pendingDupCheck = null;

    public NewRequestDialog(BackOfficeMain parent, ApiClient api) {
        super(parent, "New Request", true);
        this.parent = parent;
        this.api    = api;
        setSize(780, 600);
        setMinimumSize(new Dimension(740, 560));
        setLocationRelativeTo(parent);
        setResizable(true);
        buildUI();
        loadAgencies();
        loadAgents();
    }

    private void buildUI() {
        JPanel panel = new JPanel(new GridBagLayout());
        panel.setBorder(BorderFactory.createEmptyBorder(18, 24, 18, 24));
        GridBagConstraints c = new GridBagConstraints();
        c.fill = GridBagConstraints.HORIZONTAL;
        c.insets = new Insets(5, 6, 5, 6);

        int row = 0;

        // Customer Name
        addLabel(panel, c, "Customer Name:", row, 0);
        customerNameField = new JTextField(30);
        customerNameField.setFont(new Font("SansSerif", Font.PLAIN, 13));
        c.gridx = 1; c.gridy = row; c.gridwidth = 3; c.weightx = 1;
        panel.add(customerNameField, c);
        c.gridwidth = 1; c.weightx = 0;
        row++;

        // Duplicate warning
        dupWarningLabel = new JLabel(" ");
        dupWarningLabel.setFont(new Font("SansSerif", Font.ITALIC, 11));
        dupWarningLabel.setForeground(new Color(180, 80, 0));
        c.gridx = 1; c.gridy = row; c.gridwidth = 3; c.weightx = 1;
        panel.add(dupWarningLabel, c);
        c.gridwidth = 1; c.weightx = 0;
        row++;

        // Channel
        addLabel(panel, c, "Channel:", row, 0);
        channelCombo = new JComboBox<>(new String[]{
            "Agency", "Direct (Drct)", "Safari Bookings (SB)", "Other"
        });
        channelCombo.setFont(new Font("SansSerif", Font.PLAIN, 13));
        c.gridx = 1; c.gridy = row; c.gridwidth = 3; c.weightx = 1;
        panel.add(channelCombo, c);
        c.gridwidth = 1; c.weightx = 0;
        row++;

        // Agency (autocomplete)
        addLabel(panel, c, "Agency:", row, 0);
        agencyCombo = new JComboBox<>();
        agencyCombo.setFont(new Font("SansSerif", Font.PLAIN, 13));
        agencyCombo.addItem("-- select --");
        JButton newAgencyBtn = new JButton("+ New");
        newAgencyBtn.setFont(new Font("SansSerif", Font.PLAIN, 11));
        newAgencyBtn.setPreferredSize(new Dimension(72, 26));
        c.gridx = 1; c.gridy = row; c.gridwidth = 2; c.weightx = 0.8;
        panel.add(agencyCombo, c);
        c.gridx = 3; c.gridwidth = 1; c.weightx = 0.2;
        panel.add(newAgencyBtn, c);
        c.weightx = 0;
        row++;

        // Agent (autocomplete)
        addLabel(panel, c, "Agent:", row, 0);
        agentCombo = new JComboBox<>();
        agentCombo.setFont(new Font("SansSerif", Font.PLAIN, 13));
        c.gridx = 1; c.gridy = row; c.gridwidth = 3; c.weightx = 1;
        panel.add(agentCombo, c);
        c.gridwidth = 1; c.weightx = 0;
        row++;

        // Source
        addLabel(panel, c, "Source:", row, 0);
        sourceCombo = new JComboBox<>(SOURCES);
        sourceCombo.setFont(new Font("SansSerif", Font.PLAIN, 13));
        sourceCombo.setSelectedIndex(0); // Form is default
        c.gridx = 1; c.gridy = row; c.gridwidth = 3; c.weightx = 1;
        panel.add(sourceCombo, c);
        c.gridwidth = 1; c.weightx = 0;
        row++;

        // Pax
        addLabel(panel, c, "Pax:", row, 0);
        paxSpinner = new JSpinner(new SpinnerNumberModel(2, 1, 99, 1));
        paxSpinner.setFont(new Font("SansSerif", Font.PLAIN, 13));
        ((JSpinner.DefaultEditor) paxSpinner.getEditor()).getTextField().setColumns(4);
        c.gridx = 1; c.gridy = row; c.gridwidth = 1; c.weightx = 0.2;
        panel.add(paxSpinner, c);
        c.gridwidth = 1; c.weightx = 0;
        row++;

        // Initial Request (mandatory)
        JLabel initLabel = new JLabel("Initial Request: *");
        initLabel.setFont(new Font("SansSerif", Font.PLAIN, 12));
        c.gridx = 0; c.gridy = row; c.gridwidth = 1;
        panel.add(initLabel, c);
        row++;
        initialRequestArea = new JTextArea(8, 50);
        initialRequestArea.setFont(new Font("SansSerif", Font.PLAIN, 12));
        initialRequestArea.setLineWrap(true);
        initialRequestArea.setWrapStyleWord(true);
        JScrollPane scroll = new JScrollPane(initialRequestArea);
        scroll.setPreferredSize(new Dimension(650, 160));
        c.gridx = 0; c.gridy = row; c.gridwidth = 4; c.weightx = 1;
        c.fill = GridBagConstraints.BOTH; c.weighty = 1;
        panel.add(scroll, c);
        c.weighty = 0; c.fill = GridBagConstraints.HORIZONTAL;
        c.gridwidth = 1; c.weightx = 0;
        row++;

        // Folder preview
        JPanel previewPanel = new JPanel(new FlowLayout(FlowLayout.LEFT, 4, 0));
        JLabel previewTitle = new JLabel("Folder preview:");
        previewTitle.setFont(new Font("SansSerif", Font.BOLD, 11));
        folderPreviewLabel = new JLabel("...");
        folderPreviewLabel.setFont(new Font("Monospaced", Font.PLAIN, 12));
        folderPreviewLabel.setForeground(new Color(0, 110, 0));
        previewPanel.add(previewTitle);
        previewPanel.add(folderPreviewLabel);
        c.gridx = 0; c.gridy = row; c.gridwidth = 4; c.weightx = 1;
        panel.add(previewPanel, c);
        row++;

        // Buttons
        JButton cancelBtn = new JButton("Cancel");
        createButton = new JButton("Create Request");
        createButton.setFont(new Font("SansSerif", Font.BOLD, 13));
        createButton.setPreferredSize(new Dimension(140, 30));
        JPanel btnPanel = new JPanel(new FlowLayout(FlowLayout.RIGHT, 10, 0));
        btnPanel.add(cancelBtn);
        btnPanel.add(createButton);
        c.gridx = 0; c.gridy = row; c.gridwidth = 4;
        panel.add(btnPanel, c);

        add(new JScrollPane(panel));
        getRootPane().setDefaultButton(createButton);

        // Listeners
        customerNameField.getDocument().addDocumentListener(new DocumentListener() {
            public void insertUpdate(DocumentEvent e)  { onNameChanged(); }
            public void removeUpdate(DocumentEvent e)  { onNameChanged(); }
            public void changedUpdate(DocumentEvent e) {}
        });
        channelCombo.addActionListener(e -> {
            boolean isAgency = channelCombo.getSelectedIndex() == 0;
            agencyCombo.setEnabled(isAgency);
            newAgencyBtn.setEnabled(isAgency);
            updateFolderPreview();
        });
        agencyCombo.addActionListener(e -> updateFolderPreview());
        agentCombo.addActionListener(e -> updateFolderPreview());

        if (!AppSession.canSelectAgent) agentCombo.setEnabled(false);

        newAgencyBtn.addActionListener(e -> showNewAgencyDialog());
        cancelBtn.addActionListener(e -> { scheduler.shutdownNow(); dispose(); });
        createButton.addActionListener(e -> doCreateRequest());
    }

    private void addLabel(JPanel p, GridBagConstraints c, String text, int row, int col) {
        c.gridx = col; c.gridy = row; c.weightx = 0; c.gridwidth = 1;
        JLabel lbl = new JLabel(text);
        lbl.setFont(new Font("SansSerif", Font.PLAIN, 12));
        p.add(lbl, c);
    }

    // ── Autocomplete helper ───────────────────────────────────────────────────
    private void setupAutoComplete(JComboBox<String> combo, String[] allItems, boolean hasSelectItem) {
        combo.setEditable(true);
        // Use a stable model so editor text is not reset when contents change
        DefaultComboBoxModel<String> model = new DefaultComboBoxModel<>();
        if (hasSelectItem) model.addElement("-- select --");
        for (String item : allItems) model.addElement(item);
        combo.setModel(model);

        JTextField editor = (JTextField) combo.getEditor().getEditorComponent();
        editor.getDocument().addDocumentListener(new DocumentListener() {
            private boolean updating = false;
            private void filter() {
                if (updating || suppressFilter) return;
                updating = true;
                SwingUtilities.invokeLater(() -> {
                    String typed = editor.getText();
                    String lower = typed.toLowerCase();
                    model.removeAllElements();
                    if (typed.isEmpty() && hasSelectItem) model.addElement("-- select --");
                    for (String item : allItems) {
                        if (item.toLowerCase().contains(lower)) model.addElement(item);
                    }
                    // Restore typed text (model change may reset editor)
                    editor.setText(typed);
                    editor.setCaretPosition(typed.length());
                    if (!typed.isEmpty() && model.getSize() > 0) {
                        try { combo.showPopup(); } catch (Exception ex) {}
                    }
                    updating = false;
                    SwingUtilities.invokeLater(() -> updateFolderPreview());
                });
            }
            public void insertUpdate(DocumentEvent e) { filter(); }
            public void removeUpdate(DocumentEvent e) { filter(); }
            public void changedUpdate(DocumentEvent e) {}
        });
    }

    // ── Load data ─────────────────────────────────────────────────────────────
    private void loadAgencies() {
        new Thread(() -> {
            try {
                String resp = api.get("api_get_agencies.php", "");
                agencies = ApiClient.parseIdNomeArray(resp);
                allAgencyNames = new String[agencies.length];
                for (int i = 0; i < agencies.length; i++) allAgencyNames[i] = agencies[i][1];
                SwingUtilities.invokeLater(() -> {
                    agencyCombo.removeAllItems();
                    agencyCombo.addItem("-- select --");
                    for (String n : allAgencyNames) agencyCombo.addItem(n);
                    setupAutoComplete(agencyCombo, allAgencyNames, true);
                });
            } catch (IOException ex) {
                SwingUtilities.invokeLater(() ->
                    agencyCombo.setToolTipText("Error loading agencies: " + ex.getMessage()));
            }
        }, "load-agencies").start();
    }

    private void loadAgents() {
        new Thread(() -> {
            try {
                String resp = api.get("api_get_agents.php", "");
                agents = ApiClient.parseIdNomeArray(resp);
                allAgentNames = new String[agents.length];
                for (int i = 0; i < agents.length; i++) allAgentNames[i] = agents[i][1];
                SwingUtilities.invokeLater(() -> {
                    agentCombo.removeAllItems();
                    for (String n : allAgentNames) agentCombo.addItem(n);
                    setupAutoComplete(agentCombo, allAgentNames, false);
                    // Pre-select logged-in user's agent (suppress filter during init)
                    suppressFilter = true;
                    for (int i = 0; i < agents.length; i++) {
                        if (Integer.parseInt(agents[i][0]) == AppSession.agentId) {
                            agentCombo.setSelectedIndex(i);
                            JTextField ed = (JTextField) agentCombo.getEditor().getEditorComponent();
                            ed.setText(agents[i][1]);
                            break;
                        }
                    }
                    suppressFilter = false;
                    if (!AppSession.canSelectAgent) agentCombo.setEnabled(false);
                    updateFolderPreview();
                });
            } catch (IOException ex) {
                SwingUtilities.invokeLater(() -> {
                    agentCombo.removeAllItems();
                    agentCombo.addItem(AppSession.fullName);
                });
            }
        }, "load-agents").start();
    }

    // ── Folder preview ────────────────────────────────────────────────────────
    private void updateFolderPreview() {
        String name  = customerNameField.getText().trim();
        String camel = toCamelCase(name);
        if (camel.isEmpty()) { folderPreviewLabel.setText("..."); return; }
        String agentName = getSelectedAgentName();
        String suffix;
        switch (channelCombo.getSelectedIndex()) {
            case 0:
                String ag = getSelectedAgencyName();
                suffix = ag.isEmpty() ? "(?-" + agentName + ")" : "(" + ag + "-" + agentName + ")";
                break;
            case 1:  suffix = "(" + agentName + "-Drct)"; break;
            case 2:  suffix = "(" + agentName + "-SB)";   break;
            default: suffix = "(" + agentName + ")";      break;
        }
        folderPreviewLabel.setText(camel + suffix);
    }

    private String getSelectedAgencyName() {
        String typed = agencyCombo.isEditable()
            ? ((JTextField) agencyCombo.getEditor().getEditorComponent()).getText().trim()
            : (String) agencyCombo.getSelectedItem();
        if (typed == null || typed.isEmpty() || typed.equals("-- select --")) return "";
        return typed;
    }

    private String getSelectedAgencyId() {
        String name = getSelectedAgencyName();
        if (name.isEmpty()) return "0";
        for (String[] ag : agencies) {
            if (ag[1].equalsIgnoreCase(name)) return ag[0];
        }
        return "0";
    }

    private String getSelectedAgentName() {
        String name;
        if (agentCombo.isEditable()) {
            JTextField ed = (JTextField) agentCombo.getEditor().getEditorComponent();
            String txt = ed.getText().trim();
            name = !txt.isEmpty() ? txt : (agentCombo.getSelectedItem() != null ? agentCombo.getSelectedItem().toString() : AppSession.codiceCartella);
        } else {
            Object sel = agentCombo.getSelectedItem();
            name = sel != null ? sel.toString() : AppSession.codiceCartella;
        }
        // Remove spaces for folder name (e.g. "Roberto Capri" → "RobertoCapri")
        return name.replace(" ", "");
    }

    private String getSelectedAgentId() {
        if (!AppSession.canSelectAgent) return String.valueOf(AppSession.agentId);
        String name = getSelectedAgentName();
        for (String[] ag : agents) {
            if (ag[1].equalsIgnoreCase(name)) return ag[0];
        }
        return String.valueOf(AppSession.agentId);
    }

    // ── Duplicate check ───────────────────────────────────────────────────────
    private void onNameChanged() {
        updateFolderPreview();
        dupWarningLabel.setText(" ");
        String name = customerNameField.getText().trim();
        if (name.length() < 3) return;
        if (pendingDupCheck != null) pendingDupCheck.cancel(false);
        pendingDupCheck = scheduler.schedule(() -> checkDuplicate(name), 600, TimeUnit.MILLISECONDS);
    }

    private void checkDuplicate(String name) {
        try {
            String resp = api.get("check_duplicate.php", "name=" + urlEncode(name));
            if (resp.contains("\"level\"")) {
                String level   = ApiClient.jsonGetString(resp, "level");
                String reason  = ApiClient.jsonGetString(resp, "reason");
                String dupName = ApiClient.jsonGetString(resp, "name");
                if (level != null) {
                    String msg = "\u26A0 " + level.toUpperCase() + ": " + reason + " \u2192 \"" + dupName + "\"";
                    SwingUtilities.invokeLater(() -> {
                        dupWarningLabel.setForeground("high".equals(level) ? Color.RED : new Color(180, 80, 0));
                        dupWarningLabel.setText(msg);
                    });
                }
            }
        } catch (IOException ex) { /* silent */ }
    }

    // ── Create request ────────────────────────────────────────────────────────
    private void doCreateRequest() {
        String customerName = customerNameField.getText().trim();
        if (customerName.isEmpty()) {
            JOptionPane.showMessageDialog(this, "Enter the customer name.", "Warning", JOptionPane.WARNING_MESSAGE);
            return;
        }
        int channelIdx = channelCombo.getSelectedIndex();
        String channel;
        switch (channelIdx) {
            case 0: channel = "agency";  break;
            case 1: channel = "direct";  break;
            case 2: channel = "sb";      break;
            default: channel = "other";  break;
        }
        if ("agency".equals(channel) && getSelectedAgencyName().isEmpty()) {
            JOptionPane.showMessageDialog(this, "Please select an agency.", "Warning", JOptionPane.WARNING_MESSAGE);
            return;
        }
        if ("agency".equals(channel) && getSelectedAgentName().isEmpty()) {
            JOptionPane.showMessageDialog(this, "Please select an agent.", "Warning", JOptionPane.WARNING_MESSAGE);
            return;
        }
        if (initialRequestArea.getText().trim().isEmpty()) {
            JOptionPane.showMessageDialog(this, "Initial Request is required.", "Warning", JOptionPane.WARNING_MESSAGE);
            initialRequestArea.requestFocus();
            return;
        }

        String folderPreview = folderPreviewLabel.getText();
        int confirm = JOptionPane.showConfirmDialog(this,
            "The following folder will be created:\n  " + folderPreview + "\n\nConfirm?",
            "Confirm", JOptionPane.YES_NO_OPTION);
        if (confirm != JOptionPane.YES_OPTION) return;

        createButton.setEnabled(false);
        createButton.setText("Creating...");

        String agentId  = getSelectedAgentId();
        String agencyId = getSelectedAgencyId();
        String source   = (String) sourceCombo.getSelectedItem();
        String initReq  = initialRequestArea.getText().trim();

        StringBuilder json = new StringBuilder("{");
        json.append("\"user_id\":").append(AppSession.userId).append(",");
        json.append("\"agent_id\":").append(agentId).append(",");
        json.append("\"customer_name\":\"").append(escJson(customerName)).append("\",");
        json.append("\"channel\":\"").append(channel).append("\",");
        if ("agency".equals(channel)) json.append("\"agency_id\":").append(agencyId).append(",");
        json.append("\"source\":\"").append(escJson(source != null ? source : "Form")).append("\"");
        json.append(",\"pax\":").append((int) paxSpinner.getValue());
        if (!initReq.isEmpty()) json.append(",\"initial_request\":\"").append(escJson(initReq)).append("\"");
        json.append("}");

        new Thread(() -> {
            try {
                String resp = api.post("api_create_request.php", json.toString());
                boolean ok  = ApiClient.jsonGetBool(resp, "success");
                String msg  = ApiClient.jsonGetString(resp, "message");
                String fold = ApiClient.jsonGetString(resp, "folder_name");
                SwingUtilities.invokeLater(() -> {
                    createButton.setEnabled(true);
                    createButton.setText("Create Request");
                    if (ok) {
                        parent.setCustomerFile(fold != null ? fold : "");
                        scheduler.shutdownNow();
                        dispose();
                        JOptionPane.showMessageDialog(parent,
                            "Request created!\nFolder: " + fold, "Success", JOptionPane.INFORMATION_MESSAGE);
                    } else {
                        JOptionPane.showMessageDialog(this,
                            "Error: " + (msg != null ? msg : "unknown"), "Error", JOptionPane.ERROR_MESSAGE);
                    }
                });
            } catch (IOException ex) {
                SwingUtilities.invokeLater(() -> {
                    createButton.setEnabled(true);
                    createButton.setText("Create Request");
                    JOptionPane.showMessageDialog(this, "Network error:\n" + ex.getMessage(), "Error", JOptionPane.ERROR_MESSAGE);
                });
            }
        }, "create-request").start();
    }

    // ── New agency dialog ─────────────────────────────────────────────────────
    private void showNewAgencyDialog() {
        String nome = JOptionPane.showInputDialog(this, "New agency name:", "New Agency", JOptionPane.PLAIN_MESSAGE);
        if (nome == null || nome.trim().isEmpty()) return;
        final String finalNome = nome.trim();
        new Thread(() -> {
            try {
                String body = "{\"nome\":\"" + escJson(finalNome) + "\"}";
                String resp = api.post("api_create_agency.php", body);
                boolean ok  = ApiClient.jsonGetBool(resp, "success");
                String newId = ApiClient.jsonGetString(resp, "id");
                if (ok && newId != null) {
                    String[][] updated = new String[agencies.length + 1][2];
                    System.arraycopy(agencies, 0, updated, 0, agencies.length);
                    updated[agencies.length] = new String[]{ newId, finalNome };
                    agencies = updated;
                    // Rebuild allAgencyNames
                    allAgencyNames = new String[agencies.length];
                    for (int i = 0; i < agencies.length; i++) allAgencyNames[i] = agencies[i][1];
                    SwingUtilities.invokeLater(() -> {
                        agencyCombo.addItem(finalNome);
                        agencyCombo.setSelectedItem(finalNome);
                        JTextField ed = (JTextField) agencyCombo.getEditor().getEditorComponent();
                        ed.setText(finalNome);
                    });
                }
            } catch (IOException ex) {
                SwingUtilities.invokeLater(() ->
                    JOptionPane.showMessageDialog(this, "Error: " + ex.getMessage(), "Error", JOptionPane.ERROR_MESSAGE));
            }
        }, "new-agency").start();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private static String toCamelCase(String name) {
        if (name == null || name.trim().isEmpty()) return "";
        String trimmed = name.trim();
        // Already compact (no spaces/hyphens) — keep as-is
        if (!trimmed.contains(" ") && !trimmed.contains("-")) return trimmed;
        String[] parts = trimmed.split("[\\s\\-]+");
        StringBuilder sb = new StringBuilder();
        for (String p : parts)
            if (!p.isEmpty())
                sb.append(Character.toUpperCase(p.charAt(0))).append(p.substring(1).toLowerCase());
        return sb.toString();
    }

    private static String escJson(String s) {
        return s.replace("\\", "\\\\").replace("\"", "\\\"")
                .replace("\n", "\\n").replace("\r", "\\r").replace("\t", "\\t");
    }

    private static String urlEncode(String s) {
        try { return java.net.URLEncoder.encode(s, "UTF-8"); }
        catch (java.io.UnsupportedEncodingException e) { return s; }
    }
}
