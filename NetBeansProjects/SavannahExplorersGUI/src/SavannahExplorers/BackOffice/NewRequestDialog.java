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
    private JTextField emailField;
    private JTextField whatsappField;
    private JComboBox<String> channelCombo;
    private JComboBox<String> agencyCombo;
    private JComboBox<String> agentCombo;
    private JComboBox<String> sourceCombo;
    private JSpinner paxSpinner;
    private JTextArea initialRequestArea;
    private JLabel folderPreviewLabel;
    private JLabel dupWarningLabel;
    private JCheckBox notifyAgentCheck;
    private JButton createButton;

    private String[][] agencies = new String[0][];
    private String[][] agents   = new String[0][];
    // Full lists for autocomplete reset
    private String[] allAgencyNames = new String[0];
    private String[] allAgentNames  = new String[0];
    private boolean suppressFilter             = false; // prevents autocomplete filter during init
    // Guards setupAgencyAutoComplete() so the listener+focus handler are
    // installed exactly once, even though the model may be refreshed many times.
    private boolean agencyAutoCompleteInstalled = false;

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
        setSize(800, 820);
        setMinimumSize(new Dimension(740, 760));
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

        // Email
        addLabel(panel, c, "Email:", row, 0);
        emailField = new JTextField(30);
        emailField.setFont(new Font("SansSerif", Font.PLAIN, 13));
        c.gridx = 1; c.gridy = row; c.gridwidth = 3; c.weightx = 1;
        panel.add(emailField, c);
        c.gridwidth = 1; c.weightx = 0;
        row++;

        // WhatsApp
        addLabel(panel, c, "WhatsApp:", row, 0);
        whatsappField = new JTextField(30);
        whatsappField.setFont(new Font("SansSerif", Font.PLAIN, 13));
        c.gridx = 1; c.gridy = row; c.gridwidth = 3; c.weightx = 1;
        panel.add(whatsappField, c);
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
        sourceCombo.setSelectedIndex(1); // Email is default
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

        // Notify agent checkbox
        notifyAgentCheck = new JCheckBox("Send email notification to assigned agent");
        notifyAgentCheck.setSelected(true);
        notifyAgentCheck.setFont(new Font("SansSerif", Font.PLAIN, 12));
        c.gridx = 0; c.gridy = row; c.gridwidth = 4; c.weightx = 1;
        panel.add(notifyAgentCheck, c);
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
        emailField.addFocusListener(new java.awt.event.FocusAdapter() {
            @Override
            public void focusLost(java.awt.event.FocusEvent e) {
                String email = emailField.getText().trim();
                if (!email.isEmpty() && email.contains("@")) {
                    if (pendingDupCheck != null) pendingDupCheck.cancel(false);
                    pendingDupCheck = scheduler.schedule(() -> checkDuplicateByEmail(email), 200, TimeUnit.MILLISECONDS);
                }
            }
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

    // ── Agency autocomplete — installed ONCE, reads allAgencyNames instance field ──
    // This avoids the stale-closure bug: the listener always sees the current
    // allAgencyNames array, so adding a new agency only requires updating that
    // array and inserting one item into the existing model — no listener swap needed.
    private void setupAgencyAutoComplete() {
        if (agencyAutoCompleteInstalled) return;
        agencyAutoCompleteInstalled = true;

        agencyCombo.setEditable(true);
        final DefaultComboBoxModel<String> model =
            (DefaultComboBoxModel<String>) agencyCombo.getModel();
        final JTextField editor =
            (JTextField) agencyCombo.getEditor().getEditorComponent();

        // Clear "-- select --" placeholder on focus so user can type immediately
        editor.addFocusListener(new java.awt.event.FocusAdapter() {
            @Override
            public void focusGained(java.awt.event.FocusEvent e) {
                if ("-- select --".equals(editor.getText())) {
                    suppressFilter = true;
                    editor.setText("");
                    suppressFilter = false;
                }
            }
        });

        editor.getDocument().addDocumentListener(new DocumentListener() {
            private boolean updating = false;
            private void filter() {
                if (updating || suppressFilter) return;
                updating = true;
                SwingUtilities.invokeLater(() -> {
                    String typed = editor.getText();
                    String lower = typed.toLowerCase();
                    model.removeAllElements();
                    if (typed.isEmpty()) model.addElement("-- select --");
                    // allAgencyNames is an instance field — always reflects the
                    // current list, including any agencies added after init.
                    for (String item : allAgencyNames) {
                        if (item.toLowerCase().contains(lower)) model.addElement(item);
                    }
                    editor.setText(typed);
                    editor.setCaretPosition(typed.length());
                    if (!typed.isEmpty() && model.getSize() > 0) {
                        try { agencyCombo.showPopup(); } catch (Exception ex) {}
                    }
                    updating = false;
                    SwingUtilities.invokeLater(() -> updateFolderPreview());
                });
            }
            public void insertUpdate(DocumentEvent e)  { filter(); }
            public void removeUpdate(DocumentEvent e)  { filter(); }
            public void changedUpdate(DocumentEvent e) {}
        });
    }

    // ── Generic autocomplete helper (used for agentCombo only) ───────────────
    private DocumentListener setupAutoComplete(JComboBox<String> combo, String[] allItems, boolean hasSelectItem) {
        combo.setEditable(true);
        DefaultComboBoxModel<String> model = new DefaultComboBoxModel<>();
        if (hasSelectItem) model.addElement("-- select --");
        for (String item : allItems) model.addElement(item);
        combo.setModel(model);

        JTextField editor = (JTextField) combo.getEditor().getEditorComponent();

        if (hasSelectItem) {
            editor.addFocusListener(new java.awt.event.FocusAdapter() {
                @Override
                public void focusGained(java.awt.event.FocusEvent e) {
                    if ("-- select --".equals(editor.getText())) {
                        suppressFilter = true;
                        editor.setText("");
                        suppressFilter = false;
                    }
                }
            });
        }

        DocumentListener listener = new DocumentListener() {

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
        };
        editor.getDocument().addDocumentListener(listener);
        return listener;
    }

    // ── Load data ─────────────────────────────────────────────────────────────
    private void loadAgencies() {
        new Thread(() -> {
            try {
                String resp = api.get("api_get_agencies.php", "");
                agencies = parseAgenciesArray(resp);
                allAgencyNames = new String[agencies.length];
                for (int i = 0; i < agencies.length; i++) allAgencyNames[i] = agencies[i][1];
                SwingUtilities.invokeLater(() -> {
                    // Populate the model in-place — no model replacement, so the
                    // single DocumentListener installed by setupAgencyAutoComplete()
                    // stays valid and doesn't need reinstalling.
                    DefaultComboBoxModel<String> model =
                        (DefaultComboBoxModel<String>) agencyCombo.getModel();
                    model.removeAllElements();
                    model.addElement("-- select --");
                    for (String n : allAgencyNames) model.addElement(n);
                    // Install listener+focus handler exactly once.
                    setupAgencyAutoComplete();
                });
            } catch (IOException ex) {
                SwingUtilities.invokeLater(() ->
                    agencyCombo.setToolTipText("Error loading agencies: " + ex.getMessage()));
            }
        }, "load-agencies").start();
    }

    // Parses [{"id":1,"nome":"...","short_name":"...","type":"..."},...]
    // Returns String[][4]: [id, nome, short_name, type]
    private static String[][] parseAgenciesArray(String json) {
        java.util.List<String[]> list = new java.util.ArrayList<>();
        java.util.regex.Matcher obj = java.util.regex.Pattern
            .compile("\\{([^{}]+)\\}").matcher(json);
        while (obj.find()) {
            String block     = obj.group(1);
            String id        = jsonField(block, "id");
            String nome      = jsonField(block, "nome");
            String shortName = jsonField(block, "short_name");
            String type      = jsonField(block, "type");
            if (id == null || nome == null) continue;
            list.add(new String[]{
                id,
                nome,
                shortName != null ? shortName : toCamelCase(nome),
                type      != null ? type      : "savannah"
            });
        }
        return list.toArray(new String[0][]);
    }

    private static String jsonField(String block, String key) {
        java.util.regex.Matcher m = java.util.regex.Pattern
            .compile("\"" + key + "\"\\s*:\\s*(?:\"([^\"]*)\"|(-?\\d+))").matcher(block);
        if (m.find()) return m.group(1) != null ? m.group(1) : m.group(2);
        return null;
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
                    // Pre-select logged-in user's agent
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
                String agShort = getSelectedAgencyShortName();
                // getSelectedAgencyShortName() already appends -PS / -LAM from
                // the agency type, so use the result verbatim here.
                suffix = agShort.isEmpty() ? "(?-" + agentName + ")"
                                           : "(" + agShort + "-" + agentName + ")";
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
        // When agent is locked to the logged-in user use fullName — avoids
        // relying on agents table spelling (e.g. "Roberto" vs "Roberto Capri").
        if (!AppSession.canSelectAgent) {
            return AppSession.fullName.replace(" ", "");
        }

        // Admin (canSelectAgent=true): always use the combo value as-is.
        // Do NOT substitute fullName here — rdesibi's agentId=14 is "Roberto"
        // in the agents table, so substituting fullName would wrongly produce
        // "RobertoDeSimbi" when rdesibi selects "Roberto" for a request.
        String rawName;
        if (agentCombo.isEditable()) {
            JTextField ed = (JTextField) agentCombo.getEditor().getEditorComponent();
            String txt = ed.getText().trim();
            rawName = !txt.isEmpty() ? txt
                    : (agentCombo.getSelectedItem() != null
                            ? agentCombo.getSelectedItem().toString()
                            : AppSession.codiceCartella);
        } else {
            Object sel = agentCombo.getSelectedItem();
            rawName = sel != null ? sel.toString() : AppSession.codiceCartella;
        }
        return rawName.replace(" ", "");
    }

    private String getSelectedAgentId() {
        if (!AppSession.canSelectAgent) return String.valueOf(AppSession.agentId);
        String name = getSelectedAgentName(); // already space-free (e.g. "RobertoCapri")
        for (String[] ag : agents) {
            // Compare without spaces so "Roberto Capri" matches "RobertoCapri"
            if (ag[1].replace(" ", "").equalsIgnoreCase(name)) return ag[0];
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

    private void checkDuplicateByEmail(String email) {
        try {
            String resp = api.get("check_duplicate.php", "email=" + urlEncode(email));
            if (resp.contains("\"id\"")) {
                // Parse first match
                java.util.regex.Matcher m = java.util.regex.Pattern
                    .compile("\"name\"\\s*:\\s*\"([^\"]+)\"").matcher(resp);
                java.util.regex.Matcher mId = java.util.regex.Pattern
                    .compile("\"id\"\\s*:\\s*(\\d+)").matcher(resp);
                if (m.find() && mId.find()) {
                    String dupName = m.group(1);
                    String dupId   = mId.group(1);
                    // Count total matches
                    int count = 0;
                    java.util.regex.Matcher mc = java.util.regex.Pattern.compile("\"id\"").matcher(resp);
                    while (mc.find()) count++;
                    final String msg = "\uD83D\uDCE7 EMAIL already on file: \"" + dupName + "\" (#" + dupId + ")"
                        + (count > 1 ? " +" + (count-1) + " more" : "");
                    SwingUtilities.invokeLater(() -> {
                        dupWarningLabel.setForeground(Color.RED);
                        dupWarningLabel.setText(msg);
                    });
                }
            } else {
                // Clear any previous email warning only if name check is also clean
                SwingUtilities.invokeLater(() -> {
                    String cur = dupWarningLabel.getText();
                    if (cur != null && cur.startsWith("\uD83D\uDCE7")) {
                        dupWarningLabel.setText(" ");
                    }
                });
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

        // ── Email duplicate check before proceeding ───────────────────────────
        String emailToCheck = emailField.getText().trim();
        if (!emailToCheck.isEmpty() && emailToCheck.contains("@")) {
            try {
                String dupResp = api.get("check_duplicate.php", "email=" + urlEncode(emailToCheck));
                if (dupResp.contains("\"id\"")) {
                    // Build list of duplicates for the message
                    StringBuilder dupList = new StringBuilder();
                    java.util.regex.Matcher mName = java.util.regex.Pattern
                        .compile("\"name\"\\s*:\\s*\"([^\"]+)\"").matcher(dupResp);
                    java.util.regex.Matcher mId = java.util.regex.Pattern
                        .compile("\"id\"\\s*:\\s*(\\d+)").matcher(dupResp);
                    while (mName.find() && mId.find()) {
                        dupList.append("  • \"").append(mName.group(1))
                               .append("\"  (Request #").append(mId.group(1)).append(")\n");
                    }
                    int choice = JOptionPane.showConfirmDialog(this,
                        "⚠  WARNING — Email already on file!\n\n"
                        + "This email address is associated with:\n"
                        + dupList.toString()
                        + "\nDo you want to create a NEW request anyway?",
                        "Duplicate Email",
                        JOptionPane.YES_NO_OPTION,
                        JOptionPane.WARNING_MESSAGE);
                    if (choice != JOptionPane.YES_OPTION) return;
                }
            } catch (IOException ex) { /* network error — proceed anyway */ }
        }
        // ─────────────────────────────────────────────────────────────────────

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
        String email    = emailField.getText().trim();
        String whatsapp = whatsappField.getText().trim();

        StringBuilder json = new StringBuilder("{");
        json.append("\"user_id\":").append(AppSession.userId).append(",");
        json.append("\"agent_id\":").append(agentId).append(",");
        json.append("\"customer_name\":\"").append(escJson(customerName)).append("\",");
        json.append("\"channel\":\"").append(channel).append("\",");
        if ("agency".equals(channel)) {
            json.append("\"agency_id\":").append(agencyId).append(",");
            // Pass the pre-computed short name (already includes -PS / -LAM suffix)
            // so PHP uses it verbatim when building the Dropbox folder name,
            // rather than looking up short_name in the DB (which has no suffix).
            json.append("\"agency_short_name\":\"").append(escJson(getSelectedAgencyShortName())).append("\",");
        }
        json.append("\"source\":\"").append(escJson(source != null ? source : "Form")).append("\"");
        json.append(",\"pax\":").append((int) paxSpinner.getValue());
        if (!email.isEmpty())     json.append(",\"email\":\"").append(escJson(email)).append("\"");
        if (!whatsapp.isEmpty())  json.append(",\"whatsapp_phone_number\":\"").append(escJson(whatsapp)).append("\"");
        if (!initReq.isEmpty())   json.append(",\"initial_request\":\"").append(escJson(initReq)).append("\"");
        json.append(",\"notify_agent\":").append(notifyAgentCheck.isSelected() ? "true" : "false");
        json.append("}");

        // ── Progress dialog — appears immediately ─────────────────────────────
        JDialog progressDlg = new JDialog(this, "New Request", false);
        progressDlg.setLayout(new BorderLayout(0, 0));

        JPanel hdrPanel = new JPanel(new FlowLayout(FlowLayout.LEFT, 14, 10));
        hdrPanel.setBackground(new Color(0x1A1A2E));
        JLabel hdrLabel = new JLabel("Creating Request");
        hdrLabel.setFont(new Font("SansSerif", Font.BOLD, 14));
        hdrLabel.setForeground(Color.WHITE);
        hdrPanel.add(hdrLabel);
        progressDlg.add(hdrPanel, BorderLayout.NORTH);

        JPanel bodyPanel = new JPanel(new BorderLayout(8, 8));
        bodyPanel.setBorder(BorderFactory.createEmptyBorder(16, 16, 8, 16));
        bodyPanel.setBackground(Color.WHITE);

        JLabel statusLabel = new JLabel(
            "<html><b>In Progress…</b><br><font color='#555555'>Creating folder and saving request.</font></html>");
        statusLabel.setFont(new Font("SansSerif", Font.PLAIN, 13));
        bodyPanel.add(statusLabel, BorderLayout.NORTH);

        JProgressBar progressBar = new JProgressBar();
        progressBar.setIndeterminate(true);
        progressBar.setPreferredSize(new Dimension(400, 18));
        bodyPanel.add(progressBar, BorderLayout.CENTER);

        JTextArea detailArea = new JTextArea();
        detailArea.setEditable(false);
        detailArea.setFont(new Font("Monospaced", Font.PLAIN, 12));
        detailArea.setLineWrap(true);
        detailArea.setWrapStyleWord(true);
        detailArea.setBackground(new Color(245, 245, 245));
        detailArea.setBorder(BorderFactory.createEmptyBorder(6, 8, 6, 8));
        JScrollPane detailScroll = new JScrollPane(detailArea);
        detailScroll.setPreferredSize(new Dimension(400, 100));
        detailScroll.setBorder(null);
        detailScroll.setVisible(false);
        bodyPanel.add(detailScroll, BorderLayout.SOUTH);
        progressDlg.add(bodyPanel, BorderLayout.CENTER);

        JPanel footPanel = new JPanel(new FlowLayout(FlowLayout.RIGHT, 10, 8));
        footPanel.setBackground(new Color(0xF5F5F5));
        footPanel.setBorder(BorderFactory.createMatteBorder(1, 0, 0, 0, new Color(0xDDDDDD)));
        JButton closeBtn = new JButton("Close");
        closeBtn.setFont(new Font("SansSerif", Font.BOLD, 12));
        closeBtn.setEnabled(false);
        closeBtn.addActionListener(ev -> progressDlg.dispose());
        footPanel.add(closeBtn);
        progressDlg.add(footPanel, BorderLayout.SOUTH);

        progressDlg.pack();
        progressDlg.setSize(440, 160);
        progressDlg.setLocationRelativeTo(this);
        progressDlg.setDefaultCloseOperation(JDialog.DO_NOTHING_ON_CLOSE);
        progressDlg.setVisible(true);
        // ─────────────────────────────────────────────────────────────────────

        new Thread(() -> {
            try {
                String resp = api.post("api_create_request.php", json.toString());
                boolean ok  = ApiClient.jsonGetBool(resp, "success");
                String msg  = ApiClient.jsonGetString(resp, "message");
                String fold = ApiClient.jsonGetString(resp, "folder_name");
                SwingUtilities.invokeLater(() -> {
                    createButton.setEnabled(true);
                    createButton.setText("Create Request");
                    progressBar.setIndeterminate(false);
                    progressBar.setValue(100);
                    detailScroll.setVisible(true);
                    closeBtn.setEnabled(true);
                    progressDlg.setDefaultCloseOperation(JDialog.DISPOSE_ON_CLOSE);

                    if (ok) {
                        statusLabel.setText("<html><b style='color:#1A6B3A'>✔ Request Created</b></html>");
                        detailArea.setText("Folder: " + fold);
                        progressDlg.setSize(440, 280);
                        progressDlg.revalidate();
                        progressDlg.repaint();

                        parent.setCustomerFile(fold != null ? fold : "");
                        scheduler.shutdownNow();
                        dispose();

                        String notifyErr = ApiClient.jsonGetString(resp, "notify_error");
                        if (notifyErr != null && !notifyErr.isEmpty()) {
                            JOptionPane.showMessageDialog(parent,
                                "⚠ " + notifyErr, "Notification Warning", JOptionPane.WARNING_MESSAGE);
                        }
                    } else {
                        statusLabel.setText("<html><b style='color:#C0211B'>⚠ Error</b></html>");
                        detailArea.setText(msg != null ? msg : "Unknown error");
                        progressDlg.setSize(440, 280);
                        progressDlg.revalidate();
                        progressDlg.repaint();
                    }
                });
            } catch (IOException ex) {
                SwingUtilities.invokeLater(() -> {
                    createButton.setEnabled(true);
                    createButton.setText("Create Request");
                    progressBar.setIndeterminate(false);
                    detailScroll.setVisible(true);
                    closeBtn.setEnabled(true);
                    progressDlg.setDefaultCloseOperation(JDialog.DISPOSE_ON_CLOSE);
                    statusLabel.setText("<html><b style='color:#C0211B'>⚠ Network Error</b></html>");
                    detailArea.setText(ex.getMessage());
                    progressDlg.setSize(440, 280);
                    progressDlg.revalidate();
                    progressDlg.repaint();
                });
            }
        }, "create-request").start();
    }

    // ── New agency dialog ─────────────────────────────────────────────────────
    private void showNewAgencyDialog() {
        JDialog dlg = new JDialog(this, "New Agency", true);
        dlg.setLayout(new BorderLayout(8, 8));
        dlg.setSize(460, 440);
        dlg.setMinimumSize(new Dimension(400, 400));
        dlg.setLocationRelativeTo(this);
        dlg.setResizable(true);

        JPanel form = new JPanel(new GridBagLayout());
        form.setBorder(BorderFactory.createEmptyBorder(14, 18, 4, 18));
        GridBagConstraints gc = new GridBagConstraints();
        gc.insets = new Insets(5, 4, 5, 4);
        gc.anchor = GridBagConstraints.WEST;

        // Row 0 – Agency name
        gc.gridx = 0; gc.gridy = 0; gc.fill = GridBagConstraints.NONE; gc.weightx = 0;
        form.add(new JLabel("Agency name:"), gc);
        gc.gridx = 1; gc.fill = GridBagConstraints.HORIZONTAL; gc.weightx = 1;
        JTextField nameField = new JTextField(24);
        form.add(nameField, gc);

        // Row 1 – Short name (auto-generated, editable)
        gc.gridx = 0; gc.gridy = 1; gc.fill = GridBagConstraints.NONE; gc.weightx = 0;
        form.add(new JLabel("Short name:"), gc);
        gc.gridx = 1; gc.fill = GridBagConstraints.HORIZONTAL; gc.weightx = 1;
        JTextField shortField = new JTextField(24);
        shortField.setForeground(new Color(80, 80, 180));
        form.add(shortField, gc);

        // Row 2 – Radio buttons
        gc.gridx = 0; gc.gridy = 2; gc.gridwidth = 2; gc.fill = GridBagConstraints.NONE;
        JPanel radioPanel = new JPanel(new FlowLayout(FlowLayout.LEFT, 10, 0));
        ButtonGroup bg = new ButtonGroup();
        JRadioButton rbSav  = new JRadioButton("Savannah Explorers", true);
        JRadioButton rbPro  = new JRadioButton("Promoservice");
        JRadioButton rbLamp = new JRadioButton("Lamprati");
        bg.add(rbSav); bg.add(rbPro); bg.add(rbLamp);
        radioPanel.add(rbSav); radioPanel.add(rbPro); radioPanel.add(rbLamp);
        form.add(radioPanel, gc);

        // Row 3 – Address
        gc.gridx = 0; gc.gridy = 3; gc.gridwidth = 1; gc.fill = GridBagConstraints.NORTHWEST; gc.weightx = 0;
        form.add(new JLabel("Address:"), gc);
        gc.gridx = 1; gc.fill = GridBagConstraints.BOTH; gc.weightx = 1; gc.weighty = 1;
        JTextArea addressField = new JTextArea(5, 24);
        addressField.setLineWrap(true);
        addressField.setWrapStyleWord(true);
        addressField.setFont(new Font("SansSerif", Font.PLAIN, 13));
        addressField.setBorder(BorderFactory.createCompoundBorder(
            BorderFactory.createLineBorder(new Color(200, 200, 200)),
            BorderFactory.createEmptyBorder(4, 6, 4, 6)));
        form.add(new JScrollPane(addressField), gc);
        gc.weighty = 0;

        // Auto-update short name — includes -PS suffix for Promoservice/Lamprati
        // so the short_name in DB is self-contained (e.g. "Zucchitours-PS").
        // updateFolderPreview() uses short_name verbatim and no longer re-adds -PS.
        Runnable updateShort = () -> {
            String base   = toCamelCase(nameField.getText());
            String suffix = rbPro.isSelected()  ? "-PS"
                          : rbLamp.isSelected() ? "-LAM"
                          :                       "";
            shortField.setText(base + suffix);
        };
        nameField.getDocument().addDocumentListener(new DocumentListener() {
            public void insertUpdate(DocumentEvent e)  { updateShort.run(); }
            public void removeUpdate(DocumentEvent e)  { updateShort.run(); }
            public void changedUpdate(DocumentEvent e) { updateShort.run(); }
        });
        rbSav.addActionListener(e  -> updateShort.run());
        rbPro.addActionListener(e  -> updateShort.run());
        rbLamp.addActionListener(e -> updateShort.run());

        JButton okBtn     = new JButton("OK");
        JButton cancelBtn = new JButton("Cancel");
        okBtn.setPreferredSize(new Dimension(80, 28));
        cancelBtn.setPreferredSize(new Dimension(80, 28));
        JPanel btns = new JPanel(new FlowLayout(FlowLayout.RIGHT, 8, 6));
        btns.add(okBtn); btns.add(cancelBtn);

        dlg.add(form, BorderLayout.CENTER);
        dlg.add(btns, BorderLayout.SOUTH);
        dlg.getRootPane().setDefaultButton(okBtn);

        boolean[] confirmed = { false };
        okBtn.addActionListener(e     -> { confirmed[0] = true; dlg.dispose(); });
        cancelBtn.addActionListener(e -> dlg.dispose());
        SwingUtilities.invokeLater(() -> nameField.requestFocusInWindow());
        dlg.setVisible(true);

        if (!confirmed[0]) return;
        String nome      = nameField.getText().trim();
        String shortName = shortField.getText().trim();
        String type      = rbPro.isSelected()  ? "promoservice"
                         : rbLamp.isSelected() ? "lamprati"
                         :                       "savannah";
        if (nome.isEmpty()) return;

        final String finalNome    = nome;
        final String finalShort   = shortName;
        final String finalType    = type;
        final String finalAddress = addressField.getText().trim();
        new Thread(() -> {
            try {
                String body = "{"
                    + "\"nome\":\""       + escJson(finalNome)    + "\","
                    + "\"short_name\":\"" + escJson(finalShort)   + "\","
                    + "\"type\":\""       + finalType             + "\","
                    + "\"address\":\""    + escJson(finalAddress) + "\""
                    + "}";
                String resp = api.post("api_create_agency.php", body);
                boolean ok2  = ApiClient.jsonGetBool(resp, "success");
                // ApiClient.jsonGetString may only handle quoted string values,
                // but "id" is returned as an unquoted integer — use the local
                // jsonField() helper which handles both.
                String newId = jsonField(resp, "id");
                boolean created = (newId != null && newId.matches("\\d+"));
                if (created) {
                    String[][] updated = new String[agencies.length + 1][4];
                    System.arraycopy(agencies, 0, updated, 0, agencies.length);
                    updated[agencies.length] = new String[]{ newId, finalNome, finalShort, finalType };
                    agencies = updated;
                    allAgencyNames = new String[agencies.length];
                    for (int i = 0; i < agencies.length; i++) allAgencyNames[i] = agencies[i][1];
                    SwingUtilities.invokeLater(() -> {
                        // Suppress filter for the whole block so no queued or
                        // triggered event can leave the model in a filtered state.
                        suppressFilter = true;
                        // Rebuild the full unfiltered model from scratch — this
                        // is the only way to guarantee the new agency is visible
                        // in the LOV regardless of any prior filter state.
                        DefaultComboBoxModel<String> model =
                            (DefaultComboBoxModel<String>) agencyCombo.getModel();
                        model.removeAllElements();
                        model.addElement("-- select --");
                        for (String n : allAgencyNames) model.addElement(n);
                        // Auto-select the newly created agency
                        agencyCombo.setSelectedItem(finalNome);
                        JTextField ed = (JTextField) agencyCombo.getEditor().getEditorComponent();
                        ed.setText(finalNome);
                        suppressFilter = false;
                        updateFolderPreview();
                    });
                } else {
                    String errMsg = ApiClient.jsonGetString(resp, "message");
                    // If the server didn't return a parseable message field,
                    // show the raw response so the real cause is visible.
                    String display = (errMsg != null && !errMsg.isEmpty())
                        ? errMsg
                        : (!resp.isEmpty() ? "Server error:\n" + resp : "Could not create agency.");
                    SwingUtilities.invokeLater(() ->
                        JOptionPane.showMessageDialog(NewRequestDialog.this,
                            display, "Error", JOptionPane.ERROR_MESSAGE));
                }
            } catch (IOException ex) {
                SwingUtilities.invokeLater(() ->
                    JOptionPane.showMessageDialog(this, "Error: " + ex.getMessage(), "Error", JOptionPane.ERROR_MESSAGE));
            }
        }, "new-agency").start();
    }

    // ── Agency short name / type helpers ─────────────────────────────────────
    private String getSelectedAgencyShortName() {
        String name = getSelectedAgencyName();
        if (name.isEmpty()) return "";
        for (String[] ag : agencies) {
            if (ag[1].equalsIgnoreCase(name)) {
                String sn   = ag.length > 2 ? ag[2] : toCamelCase(ag[1]);
                String type = ag.length > 3 ? ag[3] : "savannah";
                // The DB stores short_name WITHOUT suffix; the suffix must be
                // composed here from the type field — same logic as the PHP side.
                // Strip any suffix already present in the local cache (e.g. from
                // a newly created agency whose finalShort was built with the suffix)
                // so we never end up with doubled suffixes like "Zucchitours-PS-PS".
                if (sn.endsWith("-PS"))  sn = sn.substring(0, sn.length() - 3);
                if (sn.endsWith("-LAM")) sn = sn.substring(0, sn.length() - 4);
                if ("promoservice".equals(type)) sn += "-PS";
                else if ("lamprati".equals(type)) sn += "-LAM";
                return sn;
            }
        }
        return toCamelCase(name);
    }

    private String getSelectedAgencyType() {
        String name = getSelectedAgencyName();
        if (name.isEmpty()) return "savannah";
        for (String[] ag : agencies)
            if (ag[1].equalsIgnoreCase(name)) return ag.length > 3 ? ag[3] : "savannah";
        return "savannah";
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
