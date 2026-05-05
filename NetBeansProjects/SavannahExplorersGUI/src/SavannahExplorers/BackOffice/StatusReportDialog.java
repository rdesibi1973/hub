package SavannahExplorers.BackOffice;

import java.awt.*;
import java.awt.event.*;
import java.io.*;
import java.util.*;
import java.util.List;
import java.util.function.Consumer;
import javax.swing.*;
import javax.swing.border.*;

/**
 * StatusReportDialog — non-modal popup for Bookings/Payments status reports.
 *
 * Reads folders from 001_Safari, groups them by month and status,
 * and lets the user double-click a folder name to load it into the
 * Customer File field on the main window.
 *
 * Filters: Report · Month · Status · Agent  (each supports "All")
 */
public class StatusReportDialog extends JDialog {

    // ── Status lists ──────────────────────────────────────────────────────────
    private static final String[] BOOKING_STATUSES  = {
        "PROGRESS", "PROVISIONAL", "DEPOSIT", "BALANCE-CASH", "BALANCE", "CANCELLED", "CK"
    };
    private static final String[] PAYMENT_STATUSES  = {
        "DEPOSIT", "BALANCE-CASH", "BALANCE"
    };
    private static final String[] MONTH_NAMES = {
        "", "January", "February", "March", "April", "May", "June",
        "July", "August", "September", "October", "November", "December"
    };

    // ── Colours ───────────────────────────────────────────────────────────────
    private static final Map<String, Color> STATUS_BG = new HashMap<>();
    private static final Map<String, Color> STATUS_FG = new HashMap<>();
    static {
        STATUS_BG.put("PROGRESS",     new Color(0xE8F0FE));
        STATUS_BG.put("PROVISIONAL",  new Color(0xFFF3E0));
        STATUS_BG.put("DEPOSIT",      new Color(0xEDE7F6));
        STATUS_BG.put("BALANCE",      new Color(0xFFF8E1));
        STATUS_BG.put("BALANCE-CASH", new Color(0xFFEBEE));
        STATUS_BG.put("CANCELLED",    new Color(0xF5F5F5));
        STATUS_BG.put("CK",           new Color(0xE8F5E9));
        STATUS_FG.put("PROGRESS",     new Color(0x1D6FA4));
        STATUS_FG.put("PROVISIONAL",  new Color(0xB45309));
        STATUS_FG.put("DEPOSIT",      new Color(0x6A1B9A));
        STATUS_FG.put("BALANCE",      new Color(0xD97706));
        STATUS_FG.put("BALANCE-CASH", new Color(0xC0211B));
        STATUS_FG.put("CANCELLED",    new Color(0x6B7280));
        STATUS_FG.put("CK",           new Color(0x1A6B3A));
    }
    private static final Color MONTH_HEADER_BG = new Color(0x1A1A2E);
    private static final Color MONTH_HEADER_FG = Color.WHITE;
    private static final Color AGENT_HEADER_BG = new Color(0xE8F5E9);
    private static final Color AGENT_HEADER_FG = new Color(0x1A6B3A);
    private static final Color FOLDER_FG       = new Color(0x222222);
    private static final Color FOLDER_HOVER_BG = new Color(0xE3F2FD);

    // ── Fields ────────────────────────────────────────────────────────────────
    private final String           safariPath;
    private final Consumer<String> onFolderSelected;
    private JComboBox<String>      reportCombo;
    private JComboBox<String>      monthCombo;
    private JComboBox<String>      statusCombo;
    private JComboBox<String>      agentCombo;
    private JPanel                 resultsPanel;
    private JScrollPane            scroll;
    private JLabel                 footerLabel;
    private boolean                suppressRefresh = false;

    // ── Constructor ───────────────────────────────────────────────────────────
    public StatusReportDialog(Frame parent, String safariPath, Consumer<String> onFolderSelected) {
        super(parent, "Status Reports", false);
        this.safariPath       = safariPath;
        this.onFolderSelected = onFolderSelected;
        buildUI();
        setSize(820, 660);
        setMinimumSize(new Dimension(600, 420));
        setLocationRelativeTo(parent);
    }

    // ── UI ────────────────────────────────────────────────────────────────────
    private void buildUI() {
        setLayout(new BorderLayout(0, 0));

        // ── Header toolbar ────────────────────────────────────────────────────
        JPanel toolbar = new JPanel(new FlowLayout(FlowLayout.LEFT, 10, 8));
        toolbar.setBackground(new Color(0x1A1A2E));

        reportCombo = new JComboBox<>(new String[]{ "Bookings", "Payments" });
        monthCombo  = new JComboBox<>(buildMonthItems());
        statusCombo = new JComboBox<>(new String[]{ "All" });   // populated after scan
        agentCombo  = new JComboBox<>(new String[]{ "All" });   // populated after scan

        for (JComboBox<String> cb : new JComboBox[]{ reportCombo, monthCombo, statusCombo, agentCombo }) {
            cb.setFont(new Font("SansSerif", Font.PLAIN, 12));
            cb.setPreferredSize(new Dimension(cb == reportCombo ? 130 : 150, 26));
        }

        toolbar.add(makeLabel("Report:"));  toolbar.add(reportCombo);
        toolbar.add(Box.createHorizontalStrut(6));
        toolbar.add(makeLabel("Month:"));   toolbar.add(monthCombo);
        toolbar.add(Box.createHorizontalStrut(6));
        toolbar.add(makeLabel("Status:"));  toolbar.add(statusCombo);
        toolbar.add(Box.createHorizontalStrut(6));
        toolbar.add(makeLabel("Agent:"));   toolbar.add(agentCombo);
        add(toolbar, BorderLayout.NORTH);

        // ── Results ───────────────────────────────────────────────────────────
        resultsPanel = new JPanel();
        resultsPanel.setLayout(new BoxLayout(resultsPanel, BoxLayout.Y_AXIS));
        resultsPanel.setBackground(Color.WHITE);
        scroll = new JScrollPane(resultsPanel);
        scroll.setBorder(null);
        scroll.getVerticalScrollBar().setUnitIncrement(16);
        add(scroll, BorderLayout.CENTER);

        // ── Footer ────────────────────────────────────────────────────────────
        JPanel footer = new JPanel(new BorderLayout(8, 0));
        footer.setBackground(new Color(0xF5F5F5));
        footer.setBorder(BorderFactory.createCompoundBorder(
            BorderFactory.createMatteBorder(1, 0, 0, 0, new Color(0xDDDDDD)),
            BorderFactory.createEmptyBorder(6, 10, 6, 10)));
        footerLabel = new JLabel(" ");
        footerLabel.setFont(new Font("SansSerif", Font.ITALIC, 11));
        footerLabel.setForeground(new Color(0x666666));
        footer.add(footerLabel, BorderLayout.WEST);

        JPanel btnPanel = new JPanel(new FlowLayout(FlowLayout.RIGHT, 6, 0));
        btnPanel.setOpaque(false);
        JButton refreshBtn = new JButton("\u21BB  Refresh");
        refreshBtn.setFont(new Font("SansSerif", Font.PLAIN, 12));
        refreshBtn.addActionListener(e -> refresh());
        JButton saveBtn = new JButton("\uD83D\uDCBE  Save as RTF");
        saveBtn.setFont(new Font("SansSerif", Font.PLAIN, 12));
        saveBtn.addActionListener(e -> saveAsRtf());
        JButton closeBtn = new JButton("Close");
        closeBtn.setFont(new Font("SansSerif", Font.PLAIN, 12));
        closeBtn.addActionListener(e -> dispose());
        btnPanel.add(refreshBtn); btnPanel.add(saveBtn); btnPanel.add(closeBtn);
        footer.add(btnPanel, BorderLayout.EAST);
        add(footer, BorderLayout.SOUTH);

        // ── Listeners ─────────────────────────────────────────────────────────
        ActionListener filterListener = e -> { if (!suppressRefresh) refresh(); };
        reportCombo.addActionListener(e -> {
            if (!suppressRefresh) {
                // When report changes, reset status filter and reload
                suppressRefresh = true;
                statusCombo.setSelectedItem("All");
                suppressRefresh = false;
                refresh();
            }
        });
        monthCombo.addActionListener(filterListener);
        statusCombo.addActionListener(filterListener);
        agentCombo.addActionListener(filterListener);

        refresh();
    }

    private JLabel makeLabel(String text) {
        JLabel l = new JLabel(text);
        l.setFont(new Font("SansSerif", Font.BOLD, 12));
        l.setForeground(Color.WHITE);
        return l;
    }

    private String[] buildMonthItems() {
        String[] items = new String[14];
        items[0] = "All";
        for (int i = 1; i <= 12; i++) {
            items[i] = String.format("%02d – %s", i, MONTH_NAMES[i]);
        }
        items[13] = "No month";
        return items;
    }

    // ── Refresh ───────────────────────────────────────────────────────────────
    private void refresh() {
        footerLabel.setText("Loading\u2026");
        resultsPanel.removeAll();
        resultsPanel.repaint();

        boolean payments  = reportCombo.getSelectedIndex() == 1;
        String[] statuses = payments ? PAYMENT_STATUSES : BOOKING_STATUSES;

        String selMonth  = (String) monthCombo.getSelectedItem();
        String selStatus = (String) statusCombo.getSelectedItem();
        String selAgent  = (String) agentCombo.getSelectedItem();

        new Thread(() -> {
            List<String[]> allFolders = scanFolders(statuses);

            // Populate agent and status combos from scanned data (preserve selection)
            Set<String> agentSet  = new LinkedHashSet<>();
            Set<String> statusSet = new LinkedHashSet<>();
            for (String s : statuses) statusSet.add(s);   // ordered
            for (String[] f : allFolders) agentSet.add(extractAgent(f[0]));

            List<String> agentItems = new ArrayList<>();
            agentItems.add("All");
            List<String> sortedAgents = new ArrayList<>(agentSet);
            Collections.sort(sortedAgents);
            agentItems.addAll(sortedAgents);

            List<String> statusItems = new ArrayList<>();
            statusItems.add("All");
            statusItems.addAll(statusSet);

            SwingUtilities.invokeLater(() -> {
                // Update status combo
                suppressRefresh = true;
                String curStatus = (String) statusCombo.getSelectedItem();
                statusCombo.removeAllItems();
                for (String s : statusItems) statusCombo.addItem(s);
                if (curStatus != null && statusItems.contains(curStatus)) statusCombo.setSelectedItem(curStatus);
                else statusCombo.setSelectedItem("All");

                // Update agent combo
                String curAgent = (String) agentCombo.getSelectedItem();
                agentCombo.removeAllItems();
                for (String a : agentItems) agentCombo.addItem(a);
                if (curAgent != null && agentItems.contains(curAgent)) agentCombo.setSelectedItem(curAgent);
                else agentCombo.setSelectedItem("All");
                suppressRefresh = false;

                // Apply filters
                String monthFilter  = (String) monthCombo.getSelectedItem();
                String statusFilter = (String) statusCombo.getSelectedItem();
                String agentFilter  = (String) agentCombo.getSelectedItem();

                List<String[]> filtered = new ArrayList<>();
                for (String[] f : allFolders) {
                    if (!"All".equals(monthFilter)) {
                        if ("No month".equals(monthFilter) && !f[2].isEmpty()) continue;
                        if (!"No month".equals(monthFilter)) {
                            String mm = monthFilter.substring(0, 2);
                            if (!f[2].equals(mm)) continue;
                        }
                    }
                    if (!"All".equals(statusFilter) && !statusFilter.equals(f[1])) continue;
                    if (!"All".equals(agentFilter)  && !agentFilter.equals(extractAgent(f[0]))) continue;
                    filtered.add(f);
                }

                buildResults(filtered, statuses, monthFilter, agentFilter);

                String reportName = payments ? "Payments" : "Bookings";
                footerLabel.setText(reportName + "  ·  " + filtered.size() + " of " + allFolders.size() + " folders");
            });
        }, "status-scan").start();
    }

    // ── Scan folders ──────────────────────────────────────────────────────────
    private List<String[]> scanFolders(String[] statuses) {
        List<String[]> result = new ArrayList<>();
        File base = new File(safariPath);
        if (!base.isDirectory()) return result;

        File[] subs = base.listFiles(
            f -> f.isDirectory() && f.getName().matches("^(0[1-9]|1[0-2])_.*"));
        if (subs == null) return result;

        for (File sub : subs) {
            String rawName   = sub.getName();
            String normalised = rawName.toUpperCase().replace("_BALANCE_CASH", "_BALANCE-CASH");
            String matched   = null;
            for (String s : statuses) {
                if (normalised.contains("_" + s)) { matched = s; break; }
            }
            if (matched != null) {
                String month = rawName.substring(0, 2);
                result.add(new String[]{ rawName, matched, month });
            }
        }
        result.sort(Comparator.comparing((String[] a) -> a[2]).thenComparing(a -> a[0]));
        return result;
    }

    // ── Build results ─────────────────────────────────────────────────────────
    private void buildResults(List<String[]> folders, String[] statuses,
                              String monthFilter, String agentFilter) {
        resultsPanel.removeAll();

        boolean singleMonth = !"All".equals(monthFilter) && !"No month".equals(monthFilter);
        boolean singleAgent = !"All".equals(agentFilter);

        if (singleMonth) {
            // No month header needed — jump straight to status groups
            addStatusGroups(folders, statuses, !singleAgent);
        } else {
            // Group by month
            Map<String, List<String[]>> byMonthMap = new LinkedHashMap<>();
            for (String[] f : folders) {
                byMonthMap.computeIfAbsent(f[2], k -> new ArrayList<>()).add(f);
            }
            for (Map.Entry<String, List<String[]>> me : byMonthMap.entrySet()) {
                String month = me.getKey();
                int m = 0;
                try { m = Integer.parseInt(month); } catch (Exception ignored) {}
                String monthLabel = String.format("%s  —  %s  (%d)",
                    month, m > 0 && m < MONTH_NAMES.length ? MONTH_NAMES[m] : "?",
                    me.getValue().size());
                resultsPanel.add(makeMonthHeader(monthLabel));
                addStatusGroups(me.getValue(), statuses, !singleAgent);
            }
        }

        if (folders.isEmpty()) {
            JLabel empty = new JLabel("  No folders match the selected filters");
            empty.setFont(new Font("SansSerif", Font.ITALIC, 12));
            empty.setForeground(new Color(0x888888));
            empty.setBorder(BorderFactory.createEmptyBorder(20, 16, 20, 16));
            resultsPanel.add(empty);
        }

        resultsPanel.add(Box.createVerticalGlue());
        resultsPanel.revalidate();
        resultsPanel.repaint();
        scroll.getVerticalScrollBar().setValue(0);
    }

    private void addStatusGroups(List<String[]> folders, String[] statuses, boolean byAgent) {
        for (String status : statuses) {
            List<String[]> inStatus = new ArrayList<>();
            for (String[] f : folders) if (status.equals(f[1])) inStatus.add(f);
            if (inStatus.isEmpty()) continue;

            resultsPanel.add(makeStatusHeader(status, inStatus.size()));

            if (byAgent) {
                Map<String, List<String[]>> byAgentMap = new LinkedHashMap<>();
                for (String[] f : inStatus) {
                    byAgentMap.computeIfAbsent(extractAgent(f[0]), k -> new ArrayList<>()).add(f);
                }
                for (Map.Entry<String, List<String[]>> ae : byAgentMap.entrySet()) {
                    resultsPanel.add(makeAgentHeader(ae.getKey(), ae.getValue().size()));
                    for (String[] f : ae.getValue()) resultsPanel.add(makeFolderRow(f[0]));
                }
            } else {
                for (String[] f : inStatus) resultsPanel.add(makeFolderRow(f[0]));
            }
        }
    }

    // ── Row factories ─────────────────────────────────────────────────────────
    private JPanel makeMonthHeader(String text) {
        JPanel p = new JPanel(new BorderLayout());
        p.setBackground(MONTH_HEADER_BG);
        p.setMaximumSize(new Dimension(Integer.MAX_VALUE, 32));
        p.setBorder(BorderFactory.createEmptyBorder(6, 12, 6, 12));
        JLabel lbl = new JLabel(text);
        lbl.setFont(new Font("SansSerif", Font.BOLD, 13));
        lbl.setForeground(MONTH_HEADER_FG);
        p.add(lbl, BorderLayout.WEST);
        return p;
    }

    private JPanel makeStatusHeader(String status, int count) {
        Color bg = STATUS_BG.getOrDefault(status, new Color(0xF0F0F0));
        Color fg = STATUS_FG.getOrDefault(status, Color.DARK_GRAY);
        JPanel p = new JPanel(new BorderLayout());
        p.setBackground(bg);
        p.setMaximumSize(new Dimension(Integer.MAX_VALUE, 28));
        p.setBorder(BorderFactory.createCompoundBorder(
            BorderFactory.createMatteBorder(0, 4, 0, 0, fg),
            BorderFactory.createEmptyBorder(4, 10, 4, 12)));
        JLabel lbl = new JLabel(status + "  (" + count + ")");
        lbl.setFont(new Font("SansSerif", Font.BOLD, 12));
        lbl.setForeground(fg);
        p.add(lbl, BorderLayout.WEST);
        return p;
    }

    private JPanel makeAgentHeader(String agent, int count) {
        JPanel p = new JPanel(new BorderLayout());
        p.setBackground(AGENT_HEADER_BG);
        p.setMaximumSize(new Dimension(Integer.MAX_VALUE, 24));
        p.setBorder(BorderFactory.createEmptyBorder(3, 28, 3, 12));
        JLabel lbl = new JLabel("\u25B8  " + agent + "  (" + count + ")");
        lbl.setFont(new Font("SansSerif", Font.BOLD, 11));
        lbl.setForeground(AGENT_HEADER_FG);
        p.add(lbl, BorderLayout.WEST);
        return p;
    }

    private JPanel makeFolderRow(String folderName) {
        JPanel p = new JPanel(new BorderLayout());
        p.setBackground(Color.WHITE);
        p.setMaximumSize(new Dimension(Integer.MAX_VALUE, 22));
        p.setBorder(BorderFactory.createEmptyBorder(2, 44, 2, 12));
        JLabel lbl = new JLabel("\uD83D\uDCC1  " + folderName);
        lbl.setFont(new Font("Monospaced", Font.PLAIN, 11));
        lbl.setForeground(FOLDER_FG);
        p.add(lbl, BorderLayout.WEST);
        p.addMouseListener(new MouseAdapter() {
            @Override public void mouseEntered(MouseEvent e) { p.setBackground(FOLDER_HOVER_BG); }
            @Override public void mouseExited (MouseEvent e) { p.setBackground(Color.WHITE); }
            @Override public void mouseClicked(MouseEvent e) {
                if (e.getClickCount() == 2) { onFolderSelected.accept(folderName); dispose(); }
            }
        });
        return p;
    }

    // ── Agent extraction ──────────────────────────────────────────────────────
    private static final String[] DEST_SUFFIXES_SD = {
        "-TZ-KENYA", "-SOUTHAFRICA", "-MADAGASCAR", "-BOTSWANA",
        "-NAMIBIA", "-UGANDA", "-RWANDA", "-KENYA", "-TREK", "-ZNZ"
    };

    private static String extractAgent(String folder) {
        int p1 = folder.indexOf('(');
        int p2 = folder.indexOf(')');
        if (p1 < 0 || p2 < 0 || p2 <= p1) return "?";
        String inner = folder.substring(p1 + 1, p2);
        String upper = inner.toUpperCase();
        for (String suf : DEST_SUFFIXES_SD) {
            if (upper.endsWith(suf)) { inner = inner.substring(0, inner.length() - suf.length()); break; }
        }
        int dash = inner.lastIndexOf('-');
        if (dash >= 0) {
            String after = inner.substring(dash + 1);
            if (after.equalsIgnoreCase("Drct") || after.equalsIgnoreCase("SB")) return inner.substring(0, dash);
            return after;
        }
        return inner;
    }

    // ── Save as RTF ───────────────────────────────────────────────────────────
    private void saveAsRtf() {
        JFileChooser fc = new JFileChooser();
        fc.setSelectedFile(new File("StatusReport.rtf"));
        fc.setFileFilter(new javax.swing.filechooser.FileNameExtensionFilter("RTF files (*.rtf)", "rtf"));
        if (fc.showSaveDialog(this) != JFileChooser.APPROVE_OPTION) return;
        File out = fc.getSelectedFile();
        if (!out.getName().toLowerCase().endsWith(".rtf")) out = new File(out.getAbsolutePath() + ".rtf");

        StringBuilder sb = new StringBuilder();
        sb.append("{\\rtf1\\ansi\\deff0\n{\\fonttbl{\\f0 Arial;}}\n");
        sb.append("{\\colortbl;\\red26\\green26\\blue46;\\red26\\green107\\blue58;\\red29\\green111\\blue164;\\red100\\green100\\blue100;}\n");
        for (Component c : resultsPanel.getComponents()) {
            if (!(c instanceof JPanel)) continue;
            JPanel row = (JPanel) c;
            Color bg = row.getBackground();
            for (Component child : row.getComponents()) {
                if (!(child instanceof JLabel)) continue;
                String text = ((JLabel) child).getText()
                    .replace("📁 ", "").replace("▸  ", "").trim();
                if (text.isEmpty()) continue;
                if      (bg.equals(MONTH_HEADER_BG)) sb.append("\\pard\\sb120\\b\\cf1\\f0\\fs22 ").append(rtfEsc(text)).append("\\b0\\par\n");
                else if (bg.equals(AGENT_HEADER_BG)) sb.append("\\pard\\li600\\sb40\\b\\cf2\\f0\\fs18 ").append(rtfEsc(text)).append("\\b0\\par\n");
                else if (!bg.equals(Color.WHITE))     sb.append("\\pard\\li200\\sb80\\b\\cf3\\f0\\fs20 ").append(rtfEsc(text)).append("\\b0\\par\n");
                else                                  sb.append("\\pard\\li1000\\cf4\\f0\\fs18 ").append(rtfEsc(text)).append("\\par\n");
            }
        }
        sb.append("}");
        try (BufferedWriter w = new BufferedWriter(new FileWriter(out))) {
            w.write(sb.toString());
            JOptionPane.showMessageDialog(this, "Saved to:\n" + out.getAbsolutePath(), "Saved", JOptionPane.INFORMATION_MESSAGE);
        } catch (IOException ex) {
            JOptionPane.showMessageDialog(this, "Error saving file:\n" + ex.getMessage(), "Error", JOptionPane.ERROR_MESSAGE);
        }
    }

    private static String rtfEsc(String s) {
        StringBuilder sb = new StringBuilder();
        for (char c : s.toCharArray()) {
            if      (c > 127)  sb.append("\\u").append((int) c).append('?');
            else if (c == '\\') sb.append("\\\\");
            else if (c == '{')  sb.append("\\{");
            else if (c == '}')  sb.append("\\}");
            else sb.append(c);
        }
        return sb.toString();
    }
}
