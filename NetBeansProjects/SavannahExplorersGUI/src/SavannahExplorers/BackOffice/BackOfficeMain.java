/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
package SavannahExplorers.BackOffice;
/**
 *
 * @author rdesibi
 */
import java.io.IOException;
import java.io.File;
import java.io.FileInputStream;
import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.nio.file.Paths;
import java.util.Properties;
import javax.swing.JFileChooser;
import javax.swing.SwingWorker;
import java.awt.Dimension;
import java.awt.Image;
import java.beans.PropertyChangeEvent;
import java.beans.PropertyChangeListener;
import javax.swing.ImageIcon;
import javax.swing.JLabel;
import javax.swing.JOptionPane;
import java.awt.Desktop;
import java.net.URI;
import java.net.URLEncoder;


public class BackOfficeMain extends javax.swing.JFrame {

    /** Set to true in config.properties (use.api=true) to enable API integration. */
    private static boolean USE_API = false;
    private static final String CONFIG_FILE = "config.properties";
    /** Base URL for the Leads lookup page — overridden from api.base_url in config.properties */
    private static String LEADS_LOOKUP_URL = "https://hub.savannahexplorers.com/modules/leads/lookup.php";
    /** API key loaded from config.properties — used for direct HTTP calls that need a longer timeout */
    private static String API_KEY_VALUE = "";

    /**
     * Creates new form BackOfficeMain
     */
    public BackOfficeMain() {
        initComponents();
        // Fix LUX: JViewport ignores setMaximumSize e forza jPanel1 ad allargarsi.
        // La vera causa sono i 4 JTextField senza vincoli nel GroupLayout che si espandono
        // quando jPanel1 viene allargato, aumentando PAR_C e spingendo LUX a destra.
        // Bloccando preferred+max di questi campi il layout diventa rigido.
        java.awt.Dimension fixedSearch = new java.awt.Dimension(260, 26);
        java.awt.Dimension fixedRename = new java.awt.Dimension(360, 26);
        jTextField8.setPreferredSize(fixedSearch);  jTextField8.setMaximumSize(fixedSearch);
        jTextField9.setPreferredSize(fixedSearch);  jTextField9.setMaximumSize(fixedSearch);
        jTextField6.setPreferredSize(fixedRename);  jTextField6.setMaximumSize(fixedRename);
        jTextField7.setPreferredSize(fixedRename);  jTextField7.setMaximumSize(fixedRename);
        // Set minimum window size (wider for readability)
        setMinimumSize(new java.awt.Dimension(1300, 860));
        setSize(1440, 960);

        // Set window icon (taskbar + title bar)
        java.net.URL iconUrl = getClass().getResource("logo_se.png");
        if (iconUrl != null) {
            setIconImage(new ImageIcon(iconUrl).getImage());
        }

        // Hide Browse buttons (replaced by Search with results popup)
        jSearch.setVisible(false);
        jSearchSafari.setVisible(false);

        // Hide Exit button (replaced by menu)
        jButton6.setVisible(false);

        // ── Auto-scan Prog Number when Customer Request changes ──────────────
        jTextField1.getDocument().addDocumentListener(new javax.swing.event.DocumentListener() {
            private javax.swing.Timer timer = null;
            private void schedule() {
                if (timer != null) timer.stop();
                // Debounce 400 ms so we don't scan on every keystroke
                timer = new javax.swing.Timer(400, e -> autoScanProgNumber());
                timer.setRepeats(false);
                timer.start();
            }
            public void insertUpdate(javax.swing.event.DocumentEvent e)  { schedule(); }
            public void removeUpdate(javax.swing.event.DocumentEvent e)  { schedule(); }
            public void changedUpdate(javax.swing.event.DocumentEvent e) { schedule(); }
        });

        // Add Lookup Leads menu item (opens browser with Customer Request string pre-filled)
        javax.swing.JMenu lookupMenu = new javax.swing.JMenu("Lookup Leads");
        lookupMenu.setFont(new java.awt.Font("SansSerif", java.awt.Font.BOLD, 13));
        lookupMenu.setForeground(new java.awt.Color(0, 100, 180));
        lookupMenu.addMouseListener(new java.awt.event.MouseAdapter() {
            @Override public void mousePressed(java.awt.event.MouseEvent e) {
                lookupLeadsInBrowser();
            }
        });
        jMenuBar1.add(lookupMenu);

        // Add Help menu
        javax.swing.JMenu helpMenu = new javax.swing.JMenu("Help");
        helpMenu.setFont(new java.awt.Font("SansSerif", java.awt.Font.BOLD, 13));
        javax.swing.JMenuItem helpItem = new javax.swing.JMenuItem("User Guide");
        helpItem.addActionListener(e -> showHelpDialog());
        helpMenu.add(helpItem);
        jMenuBar1.add(helpMenu);

        // Add Exit as last item in menu bar (direct click, no dropdown)
        javax.swing.JMenu exitMenu = new javax.swing.JMenu("Exit");
        exitMenu.setFont(new java.awt.Font("SansSerif", java.awt.Font.BOLD, 13));
        exitMenu.setForeground(new java.awt.Color(160, 26, 20));
        exitMenu.addMouseListener(new java.awt.event.MouseAdapter() {
            @Override public void mousePressed(java.awt.event.MouseEvent e) {
                System.exit(0);
            }
        });
        jMenuBar1.add(exitMenu);

        // Fonts handled by buildMainLayout()

        // --- Load config and optionally show login ---
        USE_API = loadConfig();
        if (USE_API) {
            LoginDialog login = new LoginDialog(this, AppSession.api);
            login.setVisible(true);
            if (!login.loginSuccessful) {
                System.exit(0);
            }
        }
    }

    /**
     * This method is called from within the constructor to initialize the form.
     * WARNING: Do NOT modify this code. The content of this method is always
     * regenerated by the Form Editor.
     */
    @SuppressWarnings("unchecked")
    // <editor-fold defaultstate="collapsed" desc="Generated Code">//GEN-BEGIN:initComponents
    private void initComponents() {

        jRadioButtonMenuItem1 = new javax.swing.JRadioButtonMenuItem();
        jMenu3 = new javax.swing.JMenu();
        jScrollPane1 = new javax.swing.JScrollPane();
        jMenuItem3 = new javax.swing.JMenuItem();
        jScrollBar2 = new javax.swing.JScrollBar();
        jScrollPane2 = new javax.swing.JScrollPane();
        jPanel1 = new javax.swing.JPanel();
        jLabel1 = new javax.swing.JLabel();
        jTextField1 = new javax.swing.JTextField();
        jButton1 = new javax.swing.JButton();
        jButton2 = new javax.swing.JButton();
        jButton3 = new javax.swing.JButton();
        jCheckBox1 = new javax.swing.JCheckBox();
        jCheckBox2 = new javax.swing.JCheckBox();
        jCheckBox3 = new javax.swing.JCheckBox();
        jButton4 = new javax.swing.JButton();
        jButton5 = new javax.swing.JButton();
        jButton6 = new javax.swing.JButton();
        jCheckBox4 = new javax.swing.JCheckBox();
        jCheckBox5 = new javax.swing.JCheckBox();
        jCheckBox6 = new javax.swing.JCheckBox();
        jCheckBox7 = new javax.swing.JCheckBox();
        jCheckBox8 = new javax.swing.JCheckBox();
        jCheckBox11 = new javax.swing.JCheckBox();
        jCheckBox12 = new javax.swing.JCheckBox();
        jCheckBox13 = new javax.swing.JCheckBox();
        jCheckBox16 = new javax.swing.JCheckBox();
        jCheckBox17 = new javax.swing.JCheckBox();
        machame = new javax.swing.JCheckBox();
        jCheckBox19 = new javax.swing.JCheckBox();
        jLabel2 = new javax.swing.JLabel();
        jLabel3 = new javax.swing.JLabel();
        jLabel4 = new javax.swing.JLabel();
        jButton11 = new javax.swing.JButton();
        jLabel5 = new javax.swing.JLabel();
        jTextField2 = new javax.swing.JTextField();
        jLabel6 = new javax.swing.JLabel();
        jTextField3 = new javax.swing.JTextField();
        jLabel8 = new javax.swing.JLabel();
        jCheckBox22 = new javax.swing.JCheckBox();
        jButton12 = new javax.swing.JButton();
        jCheckBox24 = new javax.swing.JCheckBox();
        jCheckBox25 = new javax.swing.JCheckBox();
        jCheckBox26 = new javax.swing.JCheckBox();
        jCheckBox27 = new javax.swing.JCheckBox();
        jCheckBox28 = new javax.swing.JCheckBox();
        jLabel9 = new javax.swing.JLabel();
        jTextField4 = new javax.swing.JTextField();
        jLabel10 = new javax.swing.JLabel();
        jTextField5 = new javax.swing.JComboBox<>(new String[]{"2026", "001_safari"});
        jTextField5.setEditable(true);
        jLabel11 = new javax.swing.JLabel();
        jLabel12 = new javax.swing.JLabel();
        jButton15 = new javax.swing.JButton();
        jTextField6 = new javax.swing.JTextField();
        jTextField7 = new javax.swing.JTextField();
        jTextField8 = new javax.swing.JTextField();
        jTextField9 = new javax.swing.JTextField();
        jButton17 = new javax.swing.JButton();
        jButton18 = new javax.swing.JButton();
        DumaPemba = new javax.swing.JCheckBox();
        PumbaPemba = new javax.swing.JCheckBox();
        Simba2 = new javax.swing.JCheckBox();
        GranSafari = new javax.swing.JCheckBox();
        machame6 = new javax.swing.JCheckBox();
        jDumaShort = new javax.swing.JCheckBox();
        jButton7 = new javax.swing.JButton();
        BeachDumaShort = new javax.swing.JCheckBox();
        String[] statusValues    = {"PROGRESS","PROVISIONAL","DEPOSIT","BALANCE","BALANCE-CASH","CANCELLED","PAID"};
        String[] statusValuesTo  = {"PROGRESS","PROVISIONAL","DEPOSIT","BALANCE","BALANCE-CASH","CANCELLED","PAID"};
        jComboBoxFrom = new javax.swing.JComboBox<>(statusValues);
        jComboBoxTo   = new javax.swing.JComboBox<>(statusValuesTo);
        jButtonRename = new javax.swing.JButton();
        jSearch = new javax.swing.JButton();
        jSearchSafari = new javax.swing.JButton();
        Lemosho10days = new javax.swing.JCheckBox();
        Marangu7days = new javax.swing.JCheckBox();
        SundayGRP = new javax.swing.JCheckBox();
        Simba3 = new javax.swing.JCheckBox();
        SimbaPemba = new javax.swing.JCheckBox();
        jLabel7 = new javax.swing.JLabel();
        jTextField10 = new javax.swing.JTextField();
        jLabel7b = new javax.swing.JLabel();
        jTextField10b = new javax.swing.JTextField();
        ThursdayGRP = new javax.swing.JCheckBox();
        jLabel14 = new javax.swing.JLabel();
        jLuxDuma = new javax.swing.JCheckBox();
        jLuxPumba = new javax.swing.JCheckBox();
        jLuxSimba = new javax.swing.JCheckBox();
        jDC = new javax.swing.JCheckBox();
        jPC = new javax.swing.JCheckBox();
        jSC = new javax.swing.JCheckBox();
        jKC = new javax.swing.JCheckBox();
        jButton14 = new javax.swing.JButton();
        jTextField11 = new javax.swing.JTextField();
        jButtonClearRename = new javax.swing.JButton();
        jButtonToCustomerFile = new javax.swing.JButton();
        jCheckBox9 = new javax.swing.JCheckBox();
        jMenuBar1 = new javax.swing.JMenuBar();
        jMenu1 = new javax.swing.JMenu();
        jMenuItem13 = new javax.swing.JMenuItem();
        jMenuItem16 = new javax.swing.JMenuItem();
        jMenuItem17 = new javax.swing.JMenuItem();
        jMenuItem2 = new javax.swing.JMenuItem();
        jMenu4 = new javax.swing.JMenu();
        jMenuItem6 = new javax.swing.JMenuItem();
        jMenuItem1 = new javax.swing.JMenuItem();
        jMenu5 = new javax.swing.JMenu();
        jMenuItem4 = new javax.swing.JMenuItem();

        jRadioButtonMenuItem1.setSelected(true);
        jRadioButtonMenuItem1.setText("jRadioButtonMenuItem1");

        jMenu3.setText("jMenu3");

        jMenuItem3.setText("jMenuItem3");

        setDefaultCloseOperation(javax.swing.WindowConstants.EXIT_ON_CLOSE);
        setTitle("Savannah Explorers Ltd");

        jPanel1.setName(""); // NOI18N

        jLabel1.setText("Customer Request");

        jTextField1.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jTextField1ActionPerformed(evt);
            }
        });

        jButton1.setText("NEW REQUEST");
        jButton1.setFont(new java.awt.Font("SansSerif", java.awt.Font.BOLD, 13));
        jButton1.setBackground(new java.awt.Color(80, 80, 80));
        jButton1.setForeground(java.awt.Color.WHITE);
        jButton1.setOpaque(true);
        jButton1.setBorderPainted(false);
        jButton1.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jButton1ActionPerformed(evt);
            }
        });

        jButton2.setText("Open 2026 Folder");
        jButton2.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jButton2ActionPerformed(evt);
            }
        });

        jButton3.setText("Clear");
        jButton3.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jButton3ActionPerformed(evt);
            }
        });

        jCheckBox1.setText("Duma");
        jCheckBox1.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jCheckBox1ActionPerformed(evt);
            }
        });

        jCheckBox2.setText("Pumba");

        jCheckBox3.setText("Simba");

        jButton4.setText("Copy Programs");
        jButton4.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jButton4ActionPerformed(evt);
            }
        });

        jButton5.setText("Clear Checkbox");
        jButton5.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jButton5ActionPerformed(evt);
            }
        });

        jButton6.setText("Exit");
        jButton6.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jButton6ActionPerformed(evt);
            }
        });

        jCheckBox4.setText("Kiboko");
        jCheckBox4.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jCheckBox4ActionPerformed(evt);
            }
        });

        jCheckBox5.setText("Tembo");
        jCheckBox5.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jCheckBox5ActionPerformed(evt);
            }
        });

        jCheckBox6.setText("Chui");

        jCheckBox7.setText("Faru");
        jCheckBox7.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jCheckBox7ActionPerformed(evt);
            }
        });

        jCheckBox8.setText("Mbogo");

        jCheckBox11.setText("MigrationWinter");

        jCheckBox12.setText("MigrationSummer");
        jCheckBox12.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jCheckBox12ActionPerformed(evt);
            }
        });

        jCheckBox13.setText("Ndege");

        jCheckBox16.setText("BeachPumba");
        jCheckBox16.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jCheckBox16ActionPerformed(evt);
            }
        });

        jCheckBox17.setText("BeachKiboko");

        machame.setText("Machame-9 days");
        machame.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                machameActionPerformed(evt);
            }
        });

        jCheckBox19.setText("Marangu-8 days");
        jCheckBox19.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jCheckBox19ActionPerformed(evt);
            }
        });

        jLabel2.setText("*** Safari ***");

        jLabel3.setText("*** Safari+Beach ***");

        jLabel4.setText("*** Trekking ***");

        jButton11.setText("Confirm Safari");
        jButton11.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jButton11ActionPerformed(evt);
            }
        });

        jLabel5.setText("Start Date");

        jTextField2.setText("NA");
        jTextField2.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jTextField2ActionPerformed(evt);
            }
        });

        jLabel6.setText("End Date");

        jTextField3.setText("NA");

        jLabel8.setText("*** Beach ***");

        jCheckBox22.setText("Zanzibar Safari");
        jCheckBox22.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jCheckBox22ActionPerformed(evt);
            }
        });

        jButton12.setText("Status");
        jButton12.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jButton12ActionPerformed(evt);
            }
        });

        jCheckBox24.setText("BeachSimba");

        jCheckBox25.setText("Rongai-8days");

        jCheckBox26.setText("Lemosho-9 days");
        jCheckBox26.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jCheckBox26ActionPerformed(evt);
            }
        });

        jCheckBox27.setText("Nyani");
        jCheckBox27.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jCheckBox27ActionPerformed(evt);
            }
        });

        jCheckBox28.setText("Baobab");
        jCheckBox28.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jCheckBox28ActionPerformed(evt);
            }
        });

        jLabel9.setText("ProgNumber");

        jTextField4.setText("01");

        jLabel10.setText("Rename in Folder");

        jTextField5.setSelectedItem("2026");

        jLabel11.setText("Current name");

        jLabel12.setText("New name");

        jButton15.setText("Rename");
        jButton15.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jButton15ActionPerformed(evt);
            }
        });

        jButtonClearRename.setText("Clear");
        jButtonClearRename.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jButtonClearRenameActionPerformed(evt);
            }
        });

        jButtonToCustomerFile.setText("Copy To Customer Request");
        jButtonToCustomerFile.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jButtonToCustomerFileActionPerformed(evt);
            }
        });

        jTextField7.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jTextField7ActionPerformed(evt);
            }
        });

        jTextField8.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jTextField8ActionPerformed(evt);
            }
        });

        jButton17.setText("Search 2026 Folder");
        jButton17.setToolTipText("");
        jButton17.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jButton17ActionPerformed(evt);
            }
        });

        jButton18.setText("Search 001_Safari Folder");
        jButton18.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jButton18ActionPerformed(evt);
            }
        });

        DumaPemba.setLabel("Duma+Pemba");
        DumaPemba.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                DumaPembaActionPerformed(evt);
            }
        });

        PumbaPemba.setText("Pumba+Pemba");
        PumbaPemba.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                PumbaPembaActionPerformed(evt);
            }
        });

        Simba2.setText("Simba2");

        GranSafari.setText("GranSafari");

        machame6.setText("Machame-8 days");
        machame6.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                machame6ActionPerformed(evt);
            }
        });

        jDumaShort.setText("DumaShort");
        jDumaShort.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jDumaShortActionPerformed(evt);
            }
        });

        jButton7.setText("Open 001_Safari Folder");
        jButton7.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jButton7ActionPerformed(evt);
            }
        });

        BeachDumaShort.setText("BeachDumaShort");
        BeachDumaShort.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                BeachDumaShortActionPerformed(evt);
            }
        });

        jComboBoxFrom.setSelectedIndex(0); // default FROM = PROGRESS
        jComboBoxTo.setSelectedIndex(2);   // default TO   = DEPOSIT

        jButtonRename.setText("Rename");
        jButtonRename.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jButtonRenameActionPerformed(evt);
            }
        });

        jSearch.setText("Browse 2026");
        jSearch.setActionCommand("jSearch");
        jSearch.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jSearchActionPerformed(evt);
            }
        });

        jSearchSafari.setText("Browse 001_Safari");
        jSearchSafari.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jSearchSafariActionPerformed(evt);
            }
        });

        Lemosho10days.setText("Lemosho-10 days");

        Marangu7days.setText("Marangu-7 days");
        Marangu7days.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                Marangu7daysActionPerformed(evt);
            }
        });

        SundayGRP.setText("Simba-GRP");
        SundayGRP.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                SundayGRPActionPerformed(evt);
            }
        });

        Simba3.setText("Simba3");
        Simba3.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                Simba3ActionPerformed(evt);
            }
        });

        SimbaPemba.setText("Simba + Pemba");

        jLabel7.setText("Middle Date");

        jTextField10.setText("NA");
        jTextField10.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jTextField10ActionPerformed(evt);
            }
        });

        jLabel7b.setText("Middle Date 2");

        jTextField10b.setText("NA");
        jTextField10b.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jTextField10bActionPerformed(evt);
            }
        });

        ThursdayGRP.setText("Duma-GRP");

        jLabel14.setText("*** LUX & CLASSIC SAFARI ***");

        jLuxDuma.setText("Lux Duma");
        jLuxDuma.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jLuxDumaActionPerformed(evt);
            }
        });

        jLuxPumba.setText("Lux Pumba");

        jLuxSimba.setText("Lux Simba");

        jDC.setText("Duma Classic");
        jDC.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jDCActionPerformed(evt);
            }
        });

        jPC.setText("Pumba Classic");

        jSC.setText("Simba Classic");

        jKC.setText("Kiboko Classic");

        jButton14.setText("Search 001_safari including subfolder for:");
        jButton14.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) {
                jButton14ActionPerformed(evt);
            }
        });

        jCheckBox9.setText("Gombe Katavi");

        buildMainLayout();
        // ── Menu setup ──────────────────────────────────────
        // Folders menu
        jMenu1.setText("Folders");
        jMenuItem13.setText("001_Safari");
        jMenuItem13.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) { jMenuItem13ActionPerformed(evt); }
        });
        jMenu1.add(jMenuItem13);
        jMenuItem16.setText("000_Contracts");
        jMenuItem16.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) { jMenuItem16ActionPerformed(evt); }
        });
        jMenu1.add(jMenuItem16);
        jMenuItem17.setText("Zanzibar");
        jMenuItem17.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) { jMenuItem17ActionPerformed(evt); }
        });
        jMenu1.add(jMenuItem17);
        jMenuItem2.setText("Agenzia");
        jMenuItem2.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) { jMenuItem2ActionPerformed(evt); }
        });
        jMenu1.add(jMenuItem2);
        jMenuBar1.add(jMenu1);

        // Groups & CK menu
        jMenu4.setText("Groups & CK");
        jMenu4.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) { jMenu4ActionPerformed(evt); }
        });
        jMenuItem6.setText("GRP groups");
        jMenuItem6.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) { jMenuItem6ActionPerformed(evt); }
        });
        jMenu4.add(jMenuItem6);
        jMenuItem1.setText("Missing CK");
        jMenuItem1.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) { jMenuItem1ActionPerformed(evt); }
        });
        jMenu4.add(jMenuItem1);
        jMenuBar1.add(jMenu4);

        // Bookings menu
        jMenu5.setText("Bookings");
        jMenuItem4.setText("PROGRESS");
        jMenuItem4.addActionListener(new java.awt.event.ActionListener() {
            public void actionPerformed(java.awt.event.ActionEvent evt) { jMenuItem4ActionPerformed(evt); }
        });
        jMenu5.add(jMenuItem4);
        jMenuBar1.add(jMenu5);

        // Status — dropdown with Status Reports item
        javax.swing.JMenu statusMenu = new javax.swing.JMenu("Status");
        statusMenu.setFont(new java.awt.Font("SansSerif", java.awt.Font.BOLD, 13));
        javax.swing.JMenuItem miStatus = new javax.swing.JMenuItem("Status Reports\u2026");
        miStatus.setFont(new java.awt.Font("SansSerif", java.awt.Font.PLAIN, 13));
        miStatus.addActionListener(e -> showStatusReport());
        statusMenu.add(miStatus);
        jMenuBar1.add(statusMenu);

        setJMenuBar(jMenuBar1);

        pack();
    }// </editor-fold>//GEN-END:initComponents

    // ── Clean replacement for auto-generated GroupLayout ─────────────────────
    private void buildMainLayout() {
        java.awt.Font base = new java.awt.Font("SansSerif", java.awt.Font.PLAIN, 13);
        java.awt.Font bold = new java.awt.Font("SansSerif", java.awt.Font.BOLD, 13);
        java.awt.Font big  = new java.awt.Font("SansSerif", java.awt.Font.BOLD, 15);
        java.awt.Font head = new java.awt.Font("SansSerif", java.awt.Font.BOLD, 12);

        jPanel1.setLayout(new javax.swing.BoxLayout(jPanel1, javax.swing.BoxLayout.Y_AXIS));
        jPanel1.setBorder(javax.swing.BorderFactory.createEmptyBorder(8, 14, 14, 14));
        jPanel1.setBackground(new java.awt.Color(236, 236, 236));

        // ── Logo header ──────────────────────────────────────────────────────
        java.net.URL logoUrl = getClass().getResource("logo_se.png");
        if (logoUrl != null) {
            javax.swing.JPanel headerRow = new javax.swing.JPanel(
                new java.awt.FlowLayout(java.awt.FlowLayout.LEFT, 10, 2));
            headerRow.setOpaque(false);
            headerRow.setAlignmentX(java.awt.Component.LEFT_ALIGNMENT);
            Image scaledImg = new ImageIcon(logoUrl).getImage()
                                  .getScaledInstance(52, 52, Image.SCALE_SMOOTH);
            headerRow.add(new javax.swing.JLabel(new ImageIcon(scaledImg)));
            javax.swing.JLabel titleLbl = new javax.swing.JLabel("Savannah Explorers  —  BackOffice");
            titleLbl.setFont(new java.awt.Font("SansSerif", java.awt.Font.BOLD, 17));
            titleLbl.setForeground(new java.awt.Color(0xC0211B));
            headerRow.add(titleLbl);
            jPanel1.add(headerRow);
            jPanel1.add(javax.swing.Box.createVerticalStrut(6));
        }

        // ── NEW REQUEST ──────────────────────────────────────────────────────
        jButton1.setFont(big);
        jButton1.setBackground(new java.awt.Color(70, 70, 70));
        jButton1.setForeground(java.awt.Color.WHITE);
        jButton1.setOpaque(true); jButton1.setBorderPainted(false);
        jButton1.setMaximumSize(new java.awt.Dimension(Integer.MAX_VALUE, 42));
        jButton1.setPreferredSize(new java.awt.Dimension(800, 42));
        jButton1.setAlignmentX(java.awt.Component.LEFT_ALIGNMENT);
        jPanel1.add(jButton1);
        jPanel1.add(javax.swing.Box.createVerticalStrut(8));

        // ── Customer Request row ─────────────────────────────────────────────
        javax.swing.JPanel rowCust = row();
        jLabel1.setFont(bold);
        jTextField1.setFont(base); jTextField1.setPreferredSize(new java.awt.Dimension(280, 28));
        jComboBoxFrom.setFont(base); jComboBoxFrom.setPreferredSize(new java.awt.Dimension(120, 28));
        jButtonRename.setFont(bold);
        jComboBoxTo.setFont(base); jComboBoxTo.setPreferredSize(new java.awt.Dimension(120, 28));
        rowCust.add(jLabel1); rowCust.add(jTextField1);
        rowCust.add(javax.swing.Box.createHorizontalStrut(12));
        rowCust.add(jComboBoxFrom); rowCust.add(jButtonRename); rowCust.add(jComboBoxTo);
        jPanel1.add(rowCust);
        jPanel1.add(javax.swing.Box.createVerticalStrut(4));

        // ── Open Folder buttons (below Customer Request) ─────────────────────
        javax.swing.JPanel rowFolders = row();
        setBtn(jButton2, bold, new java.awt.Dimension(200, 30));
        setBtn(jButton7, bold, new java.awt.Dimension(200, 30));
        rowFolders.add(jButton2);
        rowFolders.add(javax.swing.Box.createHorizontalStrut(10));
        rowFolders.add(jButton7);
        jPanel1.add(rowFolders);
        jPanel1.add(javax.swing.Box.createVerticalStrut(8));

        // ── Dates + Buttons ──────────────────────────────────────────────────
        javax.swing.JPanel datesPanel = new javax.swing.JPanel(new java.awt.GridBagLayout());
        datesPanel.setAlignmentX(java.awt.Component.LEFT_ALIGNMENT);
        datesPanel.setOpaque(false);
        java.awt.GridBagConstraints gc = new java.awt.GridBagConstraints();
        gc.anchor = java.awt.GridBagConstraints.WEST;
        gc.fill   = java.awt.GridBagConstraints.NONE;
        gc.insets = new java.awt.Insets(3, 6, 3, 6);

        java.awt.Dimension fld = new java.awt.Dimension(110, 28);
        java.awt.Dimension btn = new java.awt.Dimension(170, 30);
        java.awt.Dimension btn2= new java.awt.Dimension(110, 30);

        setLbl(jLabel5, bold); jTextField2.setFont(base); jTextField2.setPreferredSize(fld);
        setLbl(jLabel7, bold); jTextField10.setFont(base); jTextField10.setPreferredSize(fld);
        setLbl(jLabel7b,bold); jTextField10b.setFont(base);jTextField10b.setPreferredSize(fld);
        setLbl(jLabel6, bold); jTextField3.setFont(base); jTextField3.setPreferredSize(fld);
        setBtn(jButton2, bold, btn); setBtn(jButton7, bold, btn);
        setBtn(jButton11,bold, btn); setBtn(jButton12,bold, btn2);
        setBtn(jButton3, bold, btn2);

        // row 0: Start Date
        addC(datesPanel, jLabel5, gc, 0,0); addC(datesPanel, jTextField2, gc, 1,0);
        addC(datesPanel, jButton11, gc, 2,0);  // Confirm Safari
        // row 1: Middle Date — Clear below Confirm Safari
        addC(datesPanel, jLabel7, gc, 0,1); addC(datesPanel, jTextField10, gc, 1,1);
        addC(datesPanel, jButton3, gc, 2,1);   // Clear (below Confirm Safari)
        // row 2: Middle Date 2
        addC(datesPanel, jLabel7b, gc, 0,2); addC(datesPanel, jTextField10b, gc, 1,2);
        // Status moved to menu bar (after Bookings)
        // row 3: End Date
        addC(datesPanel, jLabel6, gc, 0,3); addC(datesPanel, jTextField3, gc, 1,3);

        // row 4: GRP
        grpActionCombo = new javax.swing.JComboBox<>(new String[]{"NONE","CREATE","ADD"});
        grpActionCombo.setFont(base);
        grpActionCombo.setPreferredSize(new java.awt.Dimension(110, 28));
        grpCodeField = new javax.swing.JTextField(6);
        grpCodeField.setFont(base);
        grpCodeField.setPreferredSize(new java.awt.Dimension(80, 28));
        grpCodeField.setEnabled(false);
        grpCodeField.setToolTipText("DDMM — e.g. 2306 = June 23");
        grpActionCombo.addActionListener(e -> {
            boolean active = !"NONE".equals(grpActionCombo.getSelectedItem());
            grpCodeField.setEnabled(active);
            if (!active) grpCodeField.setText("");
        });
        javax.swing.JLabel grpLabel = new javax.swing.JLabel("GRP:");
        setLbl(grpLabel, bold);
        addC(datesPanel, grpLabel,       gc, 0, 4);
        addC(datesPanel, grpActionCombo, gc, 1, 4);
        addC(datesPanel, grpCodeField,   gc, 2, 4);

        jPanel1.add(datesPanel);
        jPanel1.add(javax.swing.Box.createVerticalStrut(10));
        jPanel1.add(sep());

        // ── Checkbox section ─────────────────────────────────────────────────
        javax.swing.JPanel cbPanel = new javax.swing.JPanel(new java.awt.GridBagLayout());
        cbPanel.setAlignmentX(java.awt.Component.LEFT_ALIGNMENT);
        cbPanel.setOpaque(false);
        java.awt.GridBagConstraints cc = new java.awt.GridBagConstraints();
        cc.anchor = java.awt.GridBagConstraints.NORTHWEST;
        cc.fill   = java.awt.GridBagConstraints.NONE;
        cc.insets = new java.awt.Insets(2, 12, 2, 12);

        // Section headers
        setLbl(jLabel2, head); setLbl(jLabel3, head); setLbl(jLabel4, head); setLbl(jLabel14, head);
        addC(cbPanel, jLabel2,  cc, 0,0, 2,1);
        addC(cbPanel, jLabel3,  cc, 2,0);
        addC(cbPanel, jLabel4,  cc, 3,0);
        addC(cbPanel, jLabel14, cc, 4,0);

        // Apply font to all checkboxes
        for (javax.swing.JCheckBox c : new javax.swing.JCheckBox[]{
            jCheckBox1,jDumaShort,jCheckBox2,jCheckBox3,Simba2,Simba3,
            jCheckBox4,jCheckBox5,jCheckBox27,jCheckBox6,jCheckBox7,jCheckBox8,
            GranSafari,jCheckBox11,jCheckBox12,jCheckBox13,jCheckBox28,jCheckBox9,
            ThursdayGRP,SundayGRP,
            BeachDumaShort,jCheckBox16,jCheckBox24,jCheckBox17,DumaPemba,PumbaPemba,SimbaPemba,jCheckBox22,
            machame,machame6,jCheckBox19,Marangu7days,jCheckBox25,jCheckBox26,Lemosho10days,
            jLuxDuma,jLuxPumba,jLuxSimba,jDC,jPC,jSC,jKC
        }) c.setFont(base);

        // Col 0: main Safari
        javax.swing.JCheckBox[] col0 = {jCheckBox1,jDumaShort,jCheckBox2,jCheckBox3,
            Simba2,Simba3,jCheckBox4,jCheckBox5,jCheckBox27,jCheckBox6,jCheckBox7,jCheckBox8};
        for (int i=0;i<col0.length;i++) addC(cbPanel,col0[i],cc,0,i+1);

        // Col 1: GRP + Migration
        javax.swing.JCheckBox[] col1 = {GranSafari,jCheckBox11,jCheckBox12,jCheckBox13,
            jCheckBox28,jCheckBox9,ThursdayGRP,SundayGRP};
        for (int i=0;i<col1.length;i++) addC(cbPanel,col1[i],cc,1,i+1);

        // Col 2: Beach (header jLabel8 then checkboxes)
        jLabel8.setFont(head);
        addC(cbPanel, jLabel8, cc, 2, 1);
        javax.swing.JCheckBox[] col2 = {BeachDumaShort,jCheckBox16,jCheckBox24,
            jCheckBox17,DumaPemba,PumbaPemba,SimbaPemba,jCheckBox22};
        for (int i=0;i<col2.length;i++) addC(cbPanel,col2[i],cc,2,i+2);

        // Col 3: Trekking
        javax.swing.JCheckBox[] col3 = {machame,machame6,jCheckBox19,Marangu7days,
            jCheckBox25,jCheckBox26,Lemosho10days};
        for (int i=0;i<col3.length;i++) addC(cbPanel,col3[i],cc,3,i+1);

        // Col 4: LUX
        javax.swing.JCheckBox[] col4 = {jLuxDuma,jLuxPumba,jLuxSimba,jDC,jPC,jSC,jKC};
        for (int i=0;i<col4.length;i++) addC(cbPanel,col4[i],cc,4,i+1);

        // Clear Checkbox / Copy Programs / ProgNum — below Safari column
        int cbRow = col0.length + 2;
        setBtn(jButton5, bold, new java.awt.Dimension(130, 28));
        setBtn(jButton4, bold, new java.awt.Dimension(130, 28));
        jLabel9.setFont(base);
        jTextField4.setFont(base); jTextField4.setPreferredSize(new java.awt.Dimension(46, 26));
        jButtonRefreshProg = new javax.swing.JButton("↻");
        jButtonRefreshProg.setFont(bold);
        jButtonRefreshProg.setToolTipText("Re-scan folder to update prog number");
        jButtonRefreshProg.setPreferredSize(new java.awt.Dimension(36, 26));
        jButtonRefreshProg.addActionListener(e -> autoScanProgNumber());
        addC(cbPanel, jButton5, cc, 0, cbRow);
        addC(cbPanel, jButton4, cc, 1, cbRow);
        addC(cbPanel, jLabel9,  cc, 2, cbRow);
        addC(cbPanel, jTextField4,        cc, 3, cbRow);
        addC(cbPanel, jButtonRefreshProg, cc, 4, cbRow);

        jPanel1.add(javax.swing.Box.createVerticalStrut(6));
        jPanel1.add(cbPanel);
        jPanel1.add(javax.swing.Box.createVerticalStrut(8));
        jPanel1.add(sep());

        // ── Rename in Folder ─────────────────────────────────────────────────
        javax.swing.JPanel rowRenF = row();
        jLabel10.setFont(bold);
        jTextField5.setFont(base); jTextField5.setPreferredSize(new java.awt.Dimension(130, 28));
        rowRenF.add(jLabel10); rowRenF.add(jTextField5);
        jPanel1.add(rowRenF);
        jPanel1.add(javax.swing.Box.createVerticalStrut(4));

        // ── Current / New name ───────────────────────────────────────────────
        java.awt.Dimension nameFld = new java.awt.Dimension(360, 26);
        javax.swing.JPanel rowCurr = row();
        jLabel11.setFont(bold); jTextField6.setFont(base); jTextField6.setPreferredSize(nameFld);
        rowCurr.add(jLabel11); rowCurr.add(jTextField6);
        jPanel1.add(rowCurr);
        jPanel1.add(javax.swing.Box.createVerticalStrut(4));

        javax.swing.JPanel rowNew = row();
        jLabel12.setFont(bold); jTextField7.setFont(base); jTextField7.setPreferredSize(nameFld);
        rowNew.add(jLabel12); rowNew.add(jTextField7);
        jPanel1.add(rowNew);
        jPanel1.add(javax.swing.Box.createVerticalStrut(6));

        // ── Rename buttons ───────────────────────────────────────────────────
        javax.swing.JPanel rowRenBtns = row();
        setBtn(jButton15, bold, null); setBtn(jButtonClearRename, bold, null);
        setBtn(jButtonToCustomerFile, bold, null);
        rowRenBtns.add(jButton15); rowRenBtns.add(jButtonClearRename); rowRenBtns.add(jButtonToCustomerFile);
        jPanel1.add(rowRenBtns);
        jPanel1.add(javax.swing.Box.createVerticalStrut(8));
        jPanel1.add(sep());

        // ── Search ───────────────────────────────────────────────────────────
        javax.swing.JPanel rowSearch = row();
        java.awt.Dimension sFld = new java.awt.Dimension(280, 26);
        setBtn(jButton17, bold, new java.awt.Dimension(180, 30));
        jTextField8.setFont(base); jTextField8.setPreferredSize(sFld);
        setBtn(jButton18, bold, new java.awt.Dimension(200, 30));
        jTextField9.setFont(base); jTextField9.setPreferredSize(sFld);
        rowSearch.add(jButton17); rowSearch.add(jTextField8);
        rowSearch.add(javax.swing.Box.createHorizontalStrut(16));
        rowSearch.add(jButton18); rowSearch.add(jTextField9);
        jPanel1.add(rowSearch);
        jPanel1.add(javax.swing.Box.createVerticalStrut(4));

        javax.swing.JPanel rowSubSearch = row();
        setBtn(jButton14, bold, null);
        jTextField11.setFont(base); jTextField11.setPreferredSize(new java.awt.Dimension(280, 26));
        rowSubSearch.add(jButton14); rowSubSearch.add(jTextField11);
        jPanel1.add(rowSubSearch);
        jPanel1.add(javax.swing.Box.createVerticalStrut(14));

        // ── Content pane ─────────────────────────────────────────────────────
        jScrollPane2.setViewportView(jPanel1);
        jScrollPane2.setHorizontalScrollBarPolicy(javax.swing.ScrollPaneConstants.HORIZONTAL_SCROLLBAR_AS_NEEDED);
        jScrollPane2.setVerticalScrollBarPolicy(javax.swing.ScrollPaneConstants.VERTICAL_SCROLLBAR_AS_NEEDED);
        getContentPane().setLayout(new java.awt.BorderLayout());
        getContentPane().add(jScrollPane2, java.awt.BorderLayout.CENTER);
    }

    private javax.swing.JPanel row() {
        javax.swing.JPanel p = new javax.swing.JPanel(new java.awt.FlowLayout(java.awt.FlowLayout.LEFT, 6, 0));
        p.setAlignmentX(java.awt.Component.LEFT_ALIGNMENT);
        p.setOpaque(false);
        return p;
    }
    private javax.swing.JSeparator sep() {
        javax.swing.JSeparator s = new javax.swing.JSeparator();
        s.setMaximumSize(new java.awt.Dimension(Integer.MAX_VALUE, 2));
        s.setAlignmentX(java.awt.Component.LEFT_ALIGNMENT);
        return s;
    }
    private void setLbl(javax.swing.JLabel l, java.awt.Font f) { l.setFont(f); }
    private void setBtn(javax.swing.JButton b, java.awt.Font f, java.awt.Dimension d) {
        b.setFont(f);
        if (d != null) b.setPreferredSize(d);
    }
    private void addC(javax.swing.JPanel p, java.awt.Component c,
                      java.awt.GridBagConstraints gc, int x, int y) {
        gc.gridx=x; gc.gridy=y; gc.gridwidth=1; gc.gridheight=1; p.add(c, gc);
    }
    private void addC(javax.swing.JPanel p, java.awt.Component c,
                      java.awt.GridBagConstraints gc, int x, int y, int w, int h) {
        gc.gridx=x; gc.gridy=y; gc.gridwidth=w; gc.gridheight=h; p.add(c, gc);
        gc.gridwidth=1; gc.gridheight=1;
    }


    private void jMenu4ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jMenu4ActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_jMenu4ActionPerformed

    private void jMenuItem6ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jMenuItem6ActionPerformed
        // List GRP groups: cattura output, filtra solo 01_..12_, mostra popup
        String dirCmd = "dir /b \"%DROPBOX_HOME%\\001_Safari\\*GRP*\"";
        java.util.List<String> all = runDirCommand(dirCmd);
        java.util.List<String> filtered = new java.util.ArrayList<>();
        for (String line : all) {
            if (line.matches("^(0[1-9]|1[0-2])_.*")) {
                filtered.add(line);
            }
        }
        showResultsPopup("GRP Groups", filtered);
    }//GEN-LAST:event_jMenuItem6ActionPerformed

    private void jMenuItem17ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jMenuItem17ActionPerformed
        String base = System.getenv("DROPBOX_HOME");
        if (base == null || base.isEmpty()) base = "C:\\Dropbox";
        showFolderSearchDialog("Zanzibar", base + "\\000_Contracts\\Zanzibar");
    }//GEN-LAST:event_jMenuItem17ActionPerformed

    private void jMenuItem16ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jMenuItem16ActionPerformed
        String base = System.getenv("DROPBOX_HOME");
        if (base == null || base.isEmpty()) base = "C:\\Dropbox";
        showFolderSearchDialog("000_Contracts", base + "\\000_Contracts");
    }//GEN-LAST:event_jMenuItem16ActionPerformed

    private void jMenuItem13ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jMenuItem13ActionPerformed
        // TODO add your handling code here:
        try {
            String mycmd="cmd /c explorer.exe %DROPBOX_HOME%\\001_Safari";
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(mycmd);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
    }//GEN-LAST:event_jMenuItem13ActionPerformed

    private void jMenuItem2ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jMenuItem2ActionPerformed
        // Open folder "Agenzia"
        try {
            String mycmd="cmd /c explorer.exe %DROPBOX_HOME%\\itineraries\\SafariClassic\\it\\Agenzia";
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(mycmd);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
    }//GEN-LAST:event_jMenuItem2ActionPerformed

    private void jMenuItem1ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jMenuItem1ActionPerformed
        // Replicates MissingCK.bat:
        // List dated folders in 001_Safari whose NAME does NOT contain "CK".
        // Also excludes PROGRESS/PROVISIONAL (not yet confirmed) and
        // destinations that don't require CK (Kenya, Uganda, Namibia, etc.).
        String dropboxHome = System.getenv("DROPBOX_HOME");
        if (dropboxHome == null || dropboxHome.isEmpty()) {
            showScriptOutputPopup("Missing CK", "DROPBOX_HOME environment variable is not set.", true);
            return;
        }
        java.io.File safariDir = new java.io.File(dropboxHome + "\\001_Safari");
        if (!safariDir.exists() || !safariDir.isDirectory()) {
            showScriptOutputPopup("Missing CK",
                "001_Safari folder not found:\n" + safariDir.getAbsolutePath(), true);
            return;
        }

        // Matches FILTERS in MissingCK.bat — folders containing any of these are excluded
        String[] excludeWords = {
            "CK", "PROGRESS", "PROVISIONAL",
            "KENYA", "UGANDA", "NAMIBIA", "SUDAFRICA", "MADAGASCAR"
        };

        java.util.List<String> missing = new java.util.ArrayList<>();
        java.io.File[] folders = safariDir.listFiles(java.io.File::isDirectory);
        if (folders != null) {
            java.util.Arrays.sort(folders, (a, b) -> a.getName().compareToIgnoreCase(b.getName()));
            for (java.io.File folder : folders) {
                String name = folder.getName();
                // Only dated folders: MM_...
                if (!name.matches("^(0[1-9]|1[0-2])_.*")) continue;
                // Exclude if folder name contains any filter word (case-insensitive)
                String nameUpper = name.toUpperCase();
                boolean excluded = false;
                for (String word : excludeWords) {
                    if (nameUpper.contains(word)) { excluded = true; break; }
                }
                if (!excluded) missing.add(name);
            }
        }

        if (missing.isEmpty()) {
            javax.swing.JOptionPane.showMessageDialog(this,
                "All confirmed safaris have CK. Nothing missing.",
                "Missing CK", javax.swing.JOptionPane.INFORMATION_MESSAGE);
        } else {
            showResultsPopup("Missing CK — " + missing.size() + " folder(s)", missing, true);
        }
    }//GEN-LAST:event_jMenuItem1ActionPerformed

    private void jMenuItem4ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jMenuItem4ActionPerformed
        String mycmd = "dir /b \"%DROPBOX_HOME%\\001_Safari\\*PROGRESS*\"";
        java.util.List<String> results = runDirCommand(mycmd);
        showResultsPopup("PROGRESS folders", results);
    }//GEN-LAST:event_jMenuItem4ActionPerformed

    private void jDCActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jDCActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_jDCActionPerformed

    private void jLuxDumaActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jLuxDumaActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_jLuxDumaActionPerformed

    private void jTextField10ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jTextField10ActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_jTextField10ActionPerformed

    private void jTextField10bActionPerformed(java.awt.event.ActionEvent evt) {
        // TODO add your handling code here:
    }

    private void Simba3ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_Simba3ActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_Simba3ActionPerformed

    private void SundayGRPActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_SundayGRPActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_SundayGRPActionPerformed

    private void Marangu7daysActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_Marangu7daysActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_Marangu7daysActionPerformed

    private void jSearchSafariActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jSearchSafariActionPerformed
        String dropbox_home = System.getenv("DROPBOX_HOME");
        String path = dropbox_home + "\\" + "001_Safari";
        showBrowsePopup("Browse 001_Safari", path);
    }//GEN-LAST:event_jSearchSafariActionPerformed

    private void jSearchActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jSearchActionPerformed
        String dropbox_home = System.getenv("DROPBOX_HOME");
        String req_year = System.getenv("REQ_YEAR");
        String path = dropbox_home + "\\" + req_year;
        showBrowsePopup("Browse " + req_year, path);
    }//GEN-LAST:event_jSearchActionPerformed

    private void jButtonRenameActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jButtonRenameActionPerformed
        String folderName = jTextField1.getText().trim();
        if (folderName.isEmpty()) {
            javax.swing.JOptionPane.showMessageDialog(this,
                "Customer File is empty. Please select a folder first.",
                "Rename", javax.swing.JOptionPane.WARNING_MESSAGE);
            return;
        }
        String fromStatus = (String) jComboBoxFrom.getSelectedItem();
        String toStatus   = (String) jComboBoxTo.getSelectedItem();
        if (fromStatus.equals(toStatus)) {
            javax.swing.JOptionPane.showMessageDialog(this,
                "FROM and TO values are the same. Nothing to rename.",
                "Rename", javax.swing.JOptionPane.WARNING_MESSAGE);
            return;
        }

        String newFolderName;

        if ("PAID".equals(fromStatus)) {
            // FROM=PAID: folder must not already contain any status tag
            String[] allStatuses = {"PROGRESS","PROVISIONAL","DEPOSIT","BALANCE","BALANCE-CASH","CANCELLED"};
            String foundTag = null;
            for (String s : allStatuses) {
                if (folderName.contains("_" + s)) { foundTag = s; break; }
            }
            if (foundTag != null) {
                javax.swing.JOptionPane.showMessageDialog(this,
                    "The folder already contains the status \"_" + foundTag + "\":\n" + folderName,
                    "Rename", javax.swing.JOptionPane.WARNING_MESSAGE);
                return;
            }
            // Safe to append
            newFolderName = folderName + "_" + toStatus;
        } else if ("PAID".equals(toStatus)) {
            // TO=PAID: remove _FROM tag, nothing added
            if (!folderName.contains("_" + fromStatus)) {
                javax.swing.JOptionPane.showMessageDialog(this,
                    "The string \"_" + fromStatus + "\" was not found in:\n" + folderName,
                    "Rename", javax.swing.JOptionPane.WARNING_MESSAGE);
                return;
            }
            newFolderName = folderName.replace("_" + fromStatus, "");
        } else {
            // Normal case: replace _FROM with _TO
            if (!folderName.contains("_" + fromStatus)) {
                javax.swing.JOptionPane.showMessageDialog(this,
                    "The string \"_" + fromStatus + "\" was not found in:\n" + folderName,
                    "Rename", javax.swing.JOptionPane.WARNING_MESSAGE);
                return;
            }
            newFolderName = folderName.replace("_" + fromStatus, "_" + toStatus);
        }
        String dropboxHome = System.getenv("DROPBOX_HOME");
        if (dropboxHome == null) dropboxHome = "";
        String reqYear = System.getenv("REQ_YEAR");
        if (reqYear == null || reqYear.isEmpty()) reqYear = "2026";

        // Search in 001_Safari first, then in the year folder
        String base001    = dropboxHome + "\\001_Safari\\";
        String baseYear   = dropboxHome + "\\" + reqYear + "\\";
        java.io.File oldIn001  = new java.io.File(base001  + folderName);
        java.io.File oldInYear = new java.io.File(baseYear + folderName);

        String base;
        if (oldIn001.exists()) {
            base = base001;
        } else if (oldInYear.exists()) {
            base = baseYear;
        } else {
            javax.swing.JOptionPane.showMessageDialog(this,
                "Folder not found in 001_Safari or " + reqYear + ":\n" + folderName,
                "Rename", javax.swing.JOptionPane.ERROR_MESSAGE);
            return;
        }

        java.io.File oldFolder = new java.io.File(base + folderName);
        java.io.File newFolder = new java.io.File(base + newFolderName);
        if (!oldFolder.renameTo(newFolder)) {
            javax.swing.JOptionPane.showMessageDialog(this,
                "Rename failed. The folder may be open or locked:\n" + oldFolder.getAbsolutePath(),
                "Rename", javax.swing.JOptionPane.ERROR_MESSAGE);
            return;
        }
        jTextField1.setText(newFolderName);

        // ── Update DB via API ─────────────────────────────────────────────────
        if (USE_API && AppSession.isLoggedIn()) {
            final String oldName = folderName;
            final String newName = newFolderName;
            new Thread(() -> {
                try {
                    String body = "{\"old_folder_name\":\"" + oldName.replace("\\","\\\\").replace("\"","\\\"")
                                + "\",\"new_folder_name\":\"" + newName.replace("\\","\\\\").replace("\"","\\\"")
                                + "\"}";
                    String resp = postApiDirect("api_rename_folder.php", body);
                    if (resp == null || !resp.contains("\"success\":true")) {
                        javax.swing.SwingUtilities.invokeLater(() ->
                            javax.swing.JOptionPane.showMessageDialog(this,
                                "File renamed but NO corresponding request found.",
                                "Rename", javax.swing.JOptionPane.WARNING_MESSAGE));
                    }
                } catch (Exception ex) {
                    javax.swing.SwingUtilities.invokeLater(() ->
                        javax.swing.JOptionPane.showMessageDialog(this,
                            "File renamed but NO corresponding request found.",
                            "Rename", javax.swing.JOptionPane.WARNING_MESSAGE));
                }
            }).start();
        }
    }//GEN-LAST:event_jButtonRenameActionPerformed

    private void BtoPaidFullActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_BtoPaidFullActionPerformed
    }//GEN-LAST:event_BtoPaidFullActionPerformed

    private void DtoBActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_DtoBActionPerformed
    }//GEN-LAST:event_DtoBActionPerformed

    private void PtoBActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_PtoBActionPerformed
    }//GEN-LAST:event_PtoBActionPerformed

    private void PtoDActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_PtoDActionPerformed
    }//GEN-LAST:event_PtoDActionPerformed

    private void BeachDumaShortActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_BeachDumaShortActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_BeachDumaShortActionPerformed

    private void jButton7ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jButton7ActionPerformed
        // TODO add your handling code here:
        String custnamein=jTextField1.getText();
        String custname=custnamein.replaceAll("\\s", "");
        // Open folder in Windows Explorers
        try {
            String mycmd="cmd /c explorer.exe \"%DROPBOX_HOME%\\001_Safari\\\"" + custname;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(mycmd);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
    }//GEN-LAST:event_jButton7ActionPerformed

    private void jDumaShortActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jDumaShortActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_jDumaShortActionPerformed

    private void machame6ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_machame6ActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_machame6ActionPerformed

    private void PumbaPembaActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_PumbaPembaActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_PumbaPembaActionPerformed

    private void DumaPembaActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_DumaPembaActionPerformed
        // TODO add your handling code here:
        try {
            String mycmd="cmd /c explorer.exe \"%DROPBOX_HOME%\\000_Contracts\\Zanzibar\"";
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(mycmd);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
    }//GEN-LAST:event_DumaPembaActionPerformed

    private void jButton18ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jButton18ActionPerformed
        String mytext1 = jTextField9.getText();
        String mytext = mytext1.replaceAll("\\s", "");
        String mycmd = "dir /b \"%DROPBOX_HOME%\\001_Safari\\\"*" + mytext + "*";
        java.util.List<String> results = runDirCommand(mycmd);
        showResultsPopup("Search 001_Safari Folder — " + mytext, results);
    }//GEN-LAST:event_jButton18ActionPerformed

    private void jButton17ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jButton17ActionPerformed
        String mytext1 = jTextField8.getText();
        String mytext = mytext1.replaceAll("\\s", "");
        String mycmd = "dir /b \"%DROPBOX_HOME%\\%REQ_YEAR%\\\"*" + mytext + "*";
        java.util.List<String> results = runDirCommand(mycmd);
        showResultsPopup("Search 2026 Folder — " + mytext, results);
    }//GEN-LAST:event_jButton17ActionPerformed

    private void jTextField8ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jTextField8ActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_jTextField8ActionPerformed

    private void jTextField7ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jTextField7ActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_jTextField7ActionPerformed

    private void jButton15ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jButton15ActionPerformed
        try {
            String foldername = jTextField5.getSelectedItem() != null ? jTextField5.getSelectedItem().toString() : "";
            String currentname = jTextField6.getText().trim();
            String newname = jTextField7.getText().trim();
            if (currentname.isEmpty() || newname.isEmpty()) {
                javax.swing.JOptionPane.showMessageDialog(this,
                    "Please fill in both Current name and New name.", "Rename",
                    javax.swing.JOptionPane.WARNING_MESSAGE);
                return;
            }
            String dropboxHome = System.getenv("DROPBOX_HOME");
            if (dropboxHome == null) dropboxHome = "%DROPBOX_HOME%";
            java.io.File source = new java.io.File(dropboxHome + "\\" + foldername + "\\" + currentname);
            java.io.File dest   = new java.io.File(dropboxHome + "\\" + foldername + "\\" + newname);
            if (!source.exists()) {
                javax.swing.JOptionPane.showMessageDialog(this,
                    "Source not found:\n" + source.getAbsolutePath(), "Rename Error",
                    javax.swing.JOptionPane.ERROR_MESSAGE);
                return;
            }
            if (!source.renameTo(dest)) {
                javax.swing.JOptionPane.showMessageDialog(this,
                    "Rename failed (access denied or file in use):\n" + source.getAbsolutePath(), "Rename Error",
                    javax.swing.JOptionPane.ERROR_MESSAGE);
                return;
            }
            // ── Update DB practice_code via API ───────────────────────────────
            if (USE_API && AppSession.isLoggedIn()) {
                final String oldN = currentname;
                final String newN = newname;
                new Thread(() -> {
                    try {
                        String body = "{\"old_folder_name\":\"" + escJsonStatic(oldN)
                                    + "\",\"new_folder_name\":\"" + escJsonStatic(newN) + "\"}";
                        String resp = postApiDirect("api_rename_folder.php", body);
                        if (resp == null || !resp.contains("\"success\":true")) {
                            javax.swing.SwingUtilities.invokeLater(() ->
                                javax.swing.JOptionPane.showMessageDialog(this,
                                    "File renamed but NO corresponding request found.",
                                    "Rename", javax.swing.JOptionPane.WARNING_MESSAGE));
                        }
                    } catch (Exception ex) {
                        javax.swing.SwingUtilities.invokeLater(() ->
                            javax.swing.JOptionPane.showMessageDialog(this,
                                "File renamed but NO corresponding request found.",
                                "Rename", javax.swing.JOptionPane.WARNING_MESSAGE));
                    }
                }).start();
            }
        } catch (Exception ex) {
            javax.swing.JOptionPane.showMessageDialog(this,
                "Unexpected error during rename:\n" + ex.getMessage(), "Rename Error",
                javax.swing.JOptionPane.ERROR_MESSAGE);
        }
    }//GEN-LAST:event_jButton15ActionPerformed

    private void jButtonClearRenameActionPerformed(java.awt.event.ActionEvent evt) {
        jTextField6.setText("");
        jTextField7.setText("");
    }

    private void jButtonToCustomerFileActionPerformed(java.awt.event.ActionEvent evt) {
        String newname = jTextField7.getText().trim();
        if (newname.isEmpty()) {
            javax.swing.JOptionPane.showMessageDialog(this,
                "New name field is empty.", "Customer File",
                javax.swing.JOptionPane.WARNING_MESSAGE);
            return;
        }
        jTextField1.setText(newname);
    }

    private void jCheckBox27ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jCheckBox27ActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_jCheckBox27ActionPerformed

    private void jCheckBox26ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jCheckBox26ActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_jCheckBox26ActionPerformed

    private void jButton12ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jButton12ActionPerformed
        // TODO add your handling code here:
        // Remome string "OPEN" in all the file names
        try {
            String mycmd="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" Status.bat";
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(mycmd);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
    }//GEN-LAST:event_jButton12ActionPerformed

    private void jCheckBox22ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jCheckBox22ActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_jCheckBox22ActionPerformed

    private void jTextField2ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jTextField2ActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_jTextField2ActionPerformed

    private void jButton11ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jButton11ActionPerformed
        // Safari is confirmed with Start Date, End Date and other info
        // Generate the new safari folder and move to confirmed safari folder %DROPBOX_HOME%\\001_Safari
        String custname=jTextField1.getText();
        String grpAction = (grpActionCombo != null) ? (String)grpActionCombo.getSelectedItem() : "NONE";
        if (grpAction == null) grpAction = "NONE";
        String grpCode   = (grpCodeField != null) ? grpCodeField.getText().trim() : "";
        final String grpOrigCustname = custname;  // before any GRP modification

        // ── ADD GRP: no dates needed — move folder directly into existing GRP ─
        if ("ADD".equals(grpAction)) {
            if (grpCode.isEmpty()) {
                JOptionPane.showMessageDialog(null,
                    "Enter a GRP code (DDMM) to search for the GRP folder.",
                    "GRP Error", JOptionPane.ERROR_MESSAGE);
                return;
            }
            String dh0 = System.getenv("DROPBOX_HOME");
            if (dh0 == null) dh0 = "";
            java.util.List<String> found = runDirCommand(
                "dir /b \"" + dh0 + "\\001_Safari\\\"*GRP" + grpCode + "*");
            found.removeIf(f -> !f.toUpperCase().contains("GRP" + grpCode.toUpperCase()));
            if (found.isEmpty()) {
                JOptionPane.showMessageDialog(null,
                    "No GRP folder found for code " + grpCode + " in 001_Safari.",
                    "GRP Not Found", JOptionPane.WARNING_MESSAGE);
                return;
            }
            String chosen;
            if (found.size() == 1) {
                chosen = found.get(0);
            } else {
                javax.swing.JList<String> jl = new javax.swing.JList<>(found.toArray(new String[0]));
                jl.setSelectionMode(javax.swing.ListSelectionModel.SINGLE_SELECTION);
                jl.setSelectedIndex(0);
                jl.setFont(new java.awt.Font("Monospaced", java.awt.Font.PLAIN, 12));
                int res = JOptionPane.showConfirmDialog(this,
                    new javax.swing.JScrollPane(jl), "Select GRP folder",
                    JOptionPane.OK_CANCEL_OPTION, JOptionPane.PLAIN_MESSAGE);
                if (res != JOptionPane.OK_OPTION || jl.getSelectedValue() == null) return;
                chosen = jl.getSelectedValue();
            }
            launchAddGrpWorker(custname, chosen);
            return;
        }

        String startdatein=jTextField2.getText();
        String startdate=startdatein.toUpperCase();
        String intdatein=jTextField10.getText();
        String intdate=intdatein.toUpperCase();
        String intdate2in=jTextField10b.getText();
        String intdate2=intdate2in.toUpperCase();
        String enddatein=jTextField3.getText();
        String enddate=enddatein.toUpperCase();
        String month = "00";
        String endmonth = "00";
        String confirmedfolder = "00";
                
        // ── CREATE GRP: validate DDMM code and append _GRP suffix to folder name
        if ("CREATE".equals(grpAction)) {
            if (!grpCode.matches("\\d{4}")
                    || Integer.parseInt(grpCode.substring(0,2)) < 1
                    || Integer.parseInt(grpCode.substring(0,2)) > 31
                    || Integer.parseInt(grpCode.substring(2))   < 1
                    || Integer.parseInt(grpCode.substring(2))   > 12) {
                JOptionPane.showMessageDialog(null,
                    "GRP Code must be 4 digits DDMM (e.g. 2306 = June 23).",
                    "GRP Error", JOptionPane.ERROR_MESSAGE);
                return;
            }
            custname = custname + "_GRP" + grpCode;
        }

        if (startdate.matches("(?i).*JAN.*"))
        {
            month="01";
        }
        if (startdate.matches("(?i).*FEB.*"))
        {
            month="02";
        }
        if (startdate.matches("(?i).*MAR.*"))
        {
            month="03";
        }
        if (startdate.matches("(?i).*APR.*"))
        {
            month="04";
        }
        if (startdate.matches("(?i).*MAY.*"))
        {
            month="05";
        }
        if (startdate.matches("(?i).*JUN.*"))
        {
            month="06";
        }
        if (startdate.matches("(?i).*JUL.*"))
        {
            month="07";
        }
        if (startdate.matches("(?i).*AUG.*"))
        {
            month="08";
        }
        if (startdate.matches("(?i).*SEP.*"))
        {
            month="09";
        }
        if (startdate.matches("(?i).*OCT.*"))
        {
            month="10";
        }
        if (startdate.matches("(?i).*NOV.*"))
        {
            month="11";
        }
        if (startdate.matches("(?i).*DEC.*"))
        {
            month="12";
        }
        
        if (month.equals("00"))
        {
            //Terminates program and don't confirm safari as there is incorrect middle date
                        JOptionPane.showMessageDialog(null, "Incorrect Start Date Month, the correct format is MMM, for example JAN. We can't confirm the safari, check the dates and try again", "DATE ERROR", JOptionPane.ERROR_MESSAGE);
            //System.exit(0);
            return;
        }      

        if ((intdate.matches("(?i).*NA.*")) || (intdate == null) || (intdate.trim().isEmpty()))
        {
            // no middle date 1 — ignore middle date 2 as well
            confirmedfolder=month+"_"+startdate+"_"+custname+"_START"+startdate+"_END"+enddate+"_PROGRESS";
        }
        else if ((intdate.length() != 5))
        {
            JOptionPane.showMessageDialog(null, "Incorrect Middle Date, the correct format is DDMMM, for example 10JAN. We can't confirm the safari, check the dates and try again", "DATE ERROR", JOptionPane.ERROR_MESSAGE);
            return;
        }
        else
        {
            // middle date 1 is valid — check middle date 2
            boolean hasIntDate2 = !intdate2.matches("(?i).*NA.*") && !intdate2.trim().isEmpty();
            if (hasIntDate2 && intdate2.length() != 5)
            {
                JOptionPane.showMessageDialog(null, "Incorrect Middle Date 2, the correct format is DDMMM, for example 10JAN. We can't confirm the safari, check the dates and try again", "DATE ERROR", JOptionPane.ERROR_MESSAGE);
                return;
            }
            if (hasIntDate2)
            {
                confirmedfolder=month+"_"+startdate+"_"+custname+"_START"+startdate+"_MIDT"+intdate+"_MIDT"+intdate2+"_END"+enddate+"_PROGRESS";
            }
            else
            {
                confirmedfolder=month+"_"+startdate+"_"+custname+"_START"+startdate+"_MIDT"+intdate+"_END"+enddate+"_PROGRESS";
            }
        }

        if (month.matches("(?i).*00.*"))
        {
            JOptionPane.showMessageDialog(null, "Missing/Incorrect START, MIDDLE or END Date, we can't confirm the safari, check the dates and try again", "DATE ERROR", JOptionPane.ERROR_MESSAGE);
            return;
        }
        
        if ((startdate.matches("(?i).*NA.*")) || (startdate.length() != 5))
        {
            JOptionPane.showMessageDialog(null, "Missing/Incorrect START Date, the correct format is DDMMM, for example 10JAN. We can't confirm the safari, check the dates and try again", "DATE ERROR", JOptionPane.ERROR_MESSAGE);
            return;
        }

        if ((enddate.matches("(?i).*NA.*")) || (enddate.length() != 9))
        {
            JOptionPane.showMessageDialog(null, " Missing/Incorrect END Date, the correct format is DDMMMYYYY, for example 10JAN2025. We can't confirm the safari, check the dates and try again", "DATE ERROR", JOptionPane.ERROR_MESSAGE);
            return;
        }
        
        // Checking if end month is correct      
        if (enddate.matches("(?i).*JAN.*")) { endmonth="01"; }
        if (enddate.matches("(?i).*FEB.*")) { endmonth="02"; }
        if (enddate.matches("(?i).*MAR.*")) { endmonth="03"; }
        if (enddate.matches("(?i).*APR.*")) { endmonth="04"; }
        if (enddate.matches("(?i).*MAY.*")) { endmonth="05"; }
        if (enddate.matches("(?i).*JUN.*")) { endmonth="06"; }
        if (enddate.matches("(?i).*JUL.*")) { endmonth="07"; }
        if (enddate.matches("(?i).*AUG.*")) { endmonth="08"; }
        if (enddate.matches("(?i).*SEP.*")) { endmonth="09"; }
        if (enddate.matches("(?i).*OCT.*")) { endmonth="10"; }
        if (enddate.matches("(?i).*NOV.*")) { endmonth="11"; }
        if (enddate.matches("(?i).*DEC.*")) { endmonth="12"; }
        
        if (endmonth.equals("00"))
        {
            JOptionPane.showMessageDialog(null, "Incorrect End Date Month, the correct format is MMM, for example JAN. We can't confirm the safari, check the dates and try again", "DATE ERROR", JOptionPane.ERROR_MESSAGE);
            return;
        }

        // ── End date must be in the future ────────────────────────────────────
        try {
            int endDay = Integer.parseInt(enddate.substring(0, 2));
            int endYr  = Integer.parseInt(enddate.substring(5, 9)); // DDMMMYYYY: year at 5-9
            int endMo  = Integer.parseInt(endmonth) - 1; // 0-based for Calendar
            java.util.Calendar endCal = java.util.Calendar.getInstance();
            endCal.set(endYr, endMo, endDay, 23, 59, 59);
            endCal.set(java.util.Calendar.MILLISECOND, 999);
            if (!endCal.after(java.util.Calendar.getInstance())) {
                JOptionPane.showMessageDialog(null,
                    "End Date " + enddate + " is not in the future.\n"
                    + "Please check the end date and try again.",
                    "DATE ERROR", JOptionPane.ERROR_MESSAGE);
                return;
            }
        } catch (Exception ignored) { /* already validated above */ }  

        // ── Ask destination country (inserts suffix inside parentheses) ──────
        String destSuffix = askDestination();
        if (destSuffix == null) return;  // user cancelled
        if (!destSuffix.isEmpty()) {
            int close = confirmedfolder.lastIndexOf(')');
            if (close >= 0) {
                confirmedfolder = confirmedfolder.substring(0, close)
                                + destSuffix
                                + confirmedfolder.substring(close);
            }
        }

        // ADD is handled as an early return above — never reaches here
        final String grpMainFolder = "";

        // confirmedfolder is now set — run all heavy work off the EDT
        // For CREATE the folder-to-rename on disk is still the original name (before _GRP suffix)
        final String oldFolder = "CREATE".equals(grpAction) ? grpOrigCustname : custname;
        final String newFolder = confirmedfolder;
        final String grpActionFinal = grpAction;
        final String grpOrigFinal   = grpOrigCustname;

        jButton11.setEnabled(false);
        jButton11.setText("Working…");

        // ── Progress dialog — appears immediately ────────────────────────────
        javax.swing.JDialog progressDlg = new javax.swing.JDialog(this, "Confirm Safari", false);
        progressDlg.setLayout(new java.awt.BorderLayout(0, 0));

        // Header
        javax.swing.JPanel hdrPanel = new javax.swing.JPanel(new java.awt.FlowLayout(java.awt.FlowLayout.LEFT, 14, 10));
        hdrPanel.setBackground(new java.awt.Color(0x1A1A2E));
        javax.swing.JLabel hdrLabel = new javax.swing.JLabel("Confirm Safari");
        hdrLabel.setFont(new java.awt.Font("SansSerif", java.awt.Font.BOLD, 14));
        hdrLabel.setForeground(java.awt.Color.WHITE);
        hdrPanel.add(hdrLabel);
        progressDlg.add(hdrPanel, java.awt.BorderLayout.NORTH);

        // Body — progress bar + status label
        javax.swing.JPanel bodyPanel = new javax.swing.JPanel(new java.awt.BorderLayout(8, 8));
        bodyPanel.setBorder(javax.swing.BorderFactory.createEmptyBorder(16, 16, 8, 16));
        bodyPanel.setBackground(java.awt.Color.WHITE);

        javax.swing.JLabel statusLabel = new javax.swing.JLabel(
            "<html><b>In Progress…</b><br><font color='#555555'>Renaming folder and moving to 001_Safari.<br>This may take up to 15 seconds.</font></html>");
        statusLabel.setFont(new java.awt.Font("SansSerif", java.awt.Font.PLAIN, 13));
        bodyPanel.add(statusLabel, java.awt.BorderLayout.NORTH);

        javax.swing.JProgressBar progressBar = new javax.swing.JProgressBar();
        progressBar.setIndeterminate(true);
        progressBar.setPreferredSize(new java.awt.Dimension(460, 18));
        bodyPanel.add(progressBar, java.awt.BorderLayout.CENTER);

        // Detail text area (hidden initially, shown when done)
        javax.swing.JTextArea detailArea = new javax.swing.JTextArea();
        detailArea.setEditable(false);
        detailArea.setFont(new java.awt.Font("Monospaced", java.awt.Font.PLAIN, 12));
        detailArea.setLineWrap(true);
        detailArea.setWrapStyleWord(true);
        detailArea.setBackground(new java.awt.Color(245, 245, 245));
        detailArea.setBorder(javax.swing.BorderFactory.createEmptyBorder(6, 8, 6, 8));
        javax.swing.JScrollPane detailScroll = new javax.swing.JScrollPane(detailArea);
        detailScroll.setPreferredSize(new java.awt.Dimension(460, 140));
        detailScroll.setBorder(null);
        detailScroll.setVisible(false);
        bodyPanel.add(detailScroll, java.awt.BorderLayout.SOUTH);

        progressDlg.add(bodyPanel, java.awt.BorderLayout.CENTER);

        // Footer — Close button (disabled until done)
        javax.swing.JPanel footPanel = new javax.swing.JPanel(new java.awt.FlowLayout(java.awt.FlowLayout.RIGHT, 10, 8));
        footPanel.setBackground(new java.awt.Color(0xF5F5F5));
        footPanel.setBorder(javax.swing.BorderFactory.createMatteBorder(1,0,0,0, new java.awt.Color(0xDDDDDD)));
        javax.swing.JButton closeBtn = new javax.swing.JButton("Close");
        closeBtn.setFont(new java.awt.Font("SansSerif", java.awt.Font.BOLD, 12));
        closeBtn.setEnabled(false);
        closeBtn.addActionListener(ev -> progressDlg.dispose());
        footPanel.add(closeBtn);
        progressDlg.add(footPanel, java.awt.BorderLayout.SOUTH);

        progressDlg.pack();
        progressDlg.setSize(500, 180);
        progressDlg.setLocationRelativeTo(this);
        progressDlg.setDefaultCloseOperation(javax.swing.JDialog.DO_NOTHING_ON_CLOSE);
        progressDlg.setVisible(true);
        // ─────────────────────────────────────────────────────────────────────

        new SwingWorker<String, Void>() {
            @Override
            protected String doInBackground() throws Exception {
                String dropboxHome = System.getenv("DROPBOX_HOME");
                String reqYear     = System.getenv("REQ_YEAR");
                if (dropboxHome == null) dropboxHome = "";
                if (reqYear    == null) reqYear      = "2026";
                StringBuilder log = new StringBuilder();

                // ── Step 1: rename folder in year directory ──────────────────
                ProcessBuilder pb1 = new ProcessBuilder("cmd", "/c", "rename", oldFolder, newFolder);
                pb1.directory(new File(dropboxHome + "\\" + reqYear));
                pb1.redirectErrorStream(true);
                Process pr1 = pb1.start();
                try (BufferedReader r1 = new BufferedReader(new InputStreamReader(pr1.getInputStream()))) {
                    String ln;
                    while ((ln = r1.readLine()) != null) { if (!ln.isBlank()) log.append(ln).append("\n"); }
                }
                int exit1 = pr1.waitFor();
                if (exit1 != 0) {
                    return "ERROR_RENAME:Rename failed (exit " + exit1 + ")"
                           + (log.length() > 0 ? ":\n" + log : "");
                }
                log.append("✔ Folder renamed → ").append(newFolder).append("\n");

                // ── Step 2: wait for Dropbox sync (10 s, safe off EDT) ───────
                Thread.sleep(10_000);

                // ── Step 3: run cfolders.bat ──────────────────────────────────
                ProcessBuilder pb2 = new ProcessBuilder("cmd", "/c", "cfolders.bat", newFolder);
                pb2.directory(new File(dropboxHome + "\\SavannahScripts"));
                pb2.redirectErrorStream(true);
                Process pr2 = pb2.start();
                StringBuilder bat2 = new StringBuilder();
                try (BufferedReader r2 = new BufferedReader(new InputStreamReader(pr2.getInputStream()))) {
                    String ln;
                    while ((ln = r2.readLine()) != null) { if (!ln.isBlank()) bat2.append(ln).append("\n"); }
                }
                pr2.waitFor(); // exit code ignored — 'sleep' not found on Windows causes exit 1 even on success

                // Verify by checking the destination folder exists in 001_Safari
                // cfolders.bat may place the folder directly in 001_Safari OR inside
                // the yearly sub-folder 001_Safari\00_YEAR — accept either.
                File destFolder       = new File(dropboxHome + "\\001_Safari\\" + newFolder);
                File destFolderYearly = new File(dropboxHome + "\\001_Safari\\00_" + reqYear + "\\" + newFolder);
                boolean destOk = (destFolder.exists()       && destFolder.isDirectory())
                              || (destFolderYearly.exists() && destFolderYearly.isDirectory());
                if (!destOk) {
                    return "ERROR_MOVE:Folder not found in 001_Safari after cfolders.bat.\nExpected: "
                           + destFolder.getAbsolutePath()
                           + "\n  or: " + destFolderYearly.getAbsolutePath()
                           + (bat2.length() > 0 ? "\n\nScript output:\n" + bat2 : "");
                }
                if (bat2.length() > 0) log.append(bat2);
                log.append("✔ Moved to 001_Safari\n");

                // ── Step 3b (CREATE GRP): create subfolder, move files into it ─
                if ("CREATE".equals(grpActionFinal) && !grpOrigFinal.isEmpty()) {
                    java.io.File grpMain = new java.io.File(dropboxHome + "\\001_Safari\\" + newFolder);
                    java.io.File grpSub  = new java.io.File(grpMain, grpOrigFinal);
                    if (!grpSub.exists()) grpSub.mkdir();
                    java.io.File[] items = grpMain.listFiles(f -> f.isFile()
                        && !f.getName().toLowerCase().endsWith(".xlsx")
                        && !f.getName().toLowerCase().endsWith(".xls"));
                    if (items != null) {
                        for (java.io.File item : items) {
                            try {
                                java.nio.file.Files.move(item.toPath(),
                                    grpSub.toPath().resolve(item.getName()),
                                    java.nio.file.StandardCopyOption.REPLACE_EXISTING);
                            } catch (Exception ex) {
                                log.append("⚠ Move ").append(item.getName())
                                   .append(": ").append(ex.getMessage()).append("\n");
                            }
                        }
                    }
                    log.append("✔ Subfolder created: ").append(grpOrigFinal).append("\n");
                }

                // ── Step 4: update DB via API ─────────────────────────────────
                if (USE_API && AppSession.isLoggedIn()) {
                    try {
                        StringBuilder bodyBld = new StringBuilder();
                        bodyBld.append("{\"old_folder_name\":\"").append(escJson(oldFolder))
                               .append("\",\"new_folder_name\":\"").append(escJson(newFolder)).append("\"");
                        if ("CREATE".equals(grpActionFinal)) {
                            bodyBld.append(",\"grp_action\":\"CREATE\"")
                                   .append(",\"grp_subfolder\":\"").append(escJson(grpOrigFinal)).append("\"");
                        }
                        bodyBld.append("}");
                        String resp = postApiDirect("api_confirm_safari.php", bodyBld.toString());
                        if (resp != null && resp.contains("\"success\":true")) {
                            log.append("✔ DB updated: status → Booked\n");
                        } else {
                            log.append("⚠ DB update returned: ").append(resp).append("\n");
                        }
                    } catch (Exception ex) {
                        log.append("⚠ DB update error: ").append(ex.getMessage()).append("\n");
                    }
                }

                return "OK:" + log.toString();
            }

            @Override
            protected void done() {
                jButton11.setEnabled(true);
                jButton11.setText("Confirm Safari");
                progressBar.setIndeterminate(false);
                progressBar.setValue(100);
                detailScroll.setVisible(true);
                closeBtn.setEnabled(true);
                progressDlg.setDefaultCloseOperation(javax.swing.JDialog.DISPOSE_ON_CLOSE);

                try {
                    String result = get();
                    boolean isError = result.startsWith("ERROR_RENAME:") || result.startsWith("ERROR_MOVE:");
                    String detail;
                    if (result.startsWith("ERROR_RENAME:")) {
                        detail = result.substring("ERROR_RENAME:".length());
                        statusLabel.setText("<html><b style='color:#C0211B'>⚠ Rename Error</b></html>");
                    } else if (result.startsWith("ERROR_MOVE:")) {
                        detail = result.substring("ERROR_MOVE:".length());
                        statusLabel.setText("<html><b style='color:#C0211B'>⚠ Move Error</b></html>");
                    } else {
                        detail = result.substring("OK:".length());
                        statusLabel.setText("<html><b style='color:#1A6B3A'>✔ Done</b></html>");
                        jTextField1.setText(newFolder);
                        // Show email compose popup for booking notification
                        final String capturedFolder = newFolder;
                        javax.swing.SwingUtilities.invokeLater(() -> showSafariBookingEmailDialog(capturedFolder));
                    }
                    detailArea.setText(detail != null ? detail.trim() : "(no output)");
                } catch (java.util.concurrent.ExecutionException ex) {
                    statusLabel.setText("<html><b style='color:#C0211B'>⚠ Error</b></html>");
                    detailArea.setText(ex.getCause() != null ? ex.getCause().getMessage() : ex.getMessage());
                } catch (Exception ex) {
                    statusLabel.setText("<html><b style='color:#C0211B'>⚠ Error</b></html>");
                    detailArea.setText(ex.getMessage());
                }

                progressDlg.setSize(500, 320);
                progressDlg.revalidate();
                progressDlg.repaint();
                detailArea.setCaretPosition(0);
            }

            /** Escape a string for inline JSON. */
            private String escJson(String s) {
                if (s == null) return "";
                return s.replace("\\", "\\\\").replace("\"", "\\\"");
            }
        }.execute();
    }//GEN-LAST:event_jButton11ActionPerformed

    private void jCheckBox19ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jCheckBox19ActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_jCheckBox19ActionPerformed

    private void machameActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_machameActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_machameActionPerformed

    private void jCheckBox16ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jCheckBox16ActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_jCheckBox16ActionPerformed

    private void jCheckBox12ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jCheckBox12ActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_jCheckBox12ActionPerformed

    private void jCheckBox7ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jCheckBox7ActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_jCheckBox7ActionPerformed

    private void jCheckBox5ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jCheckBox5ActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_jCheckBox5ActionPerformed

    private void jCheckBox4ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jCheckBox4ActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_jCheckBox4ActionPerformed

    private void jButton6ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jButton6ActionPerformed
        System.exit(0);
    }//GEN-LAST:event_jButton6ActionPerformed

    private void jButton5ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jButton5ActionPerformed
        jCheckBox1.setSelected(false);
        jCheckBox2.setSelected(false);
        jCheckBox3.setSelected(false);
        jCheckBox4.setSelected(false);
        jCheckBox5.setSelected(false);
        jCheckBox6.setSelected(false);
        jCheckBox7.setSelected(false);
        jCheckBox8.setSelected(false);

        jCheckBox11.setSelected(false);
        jCheckBox12.setSelected(false);
        jCheckBox13.setSelected(false);
        

        jCheckBox16.setSelected(false);
        jCheckBox17.setSelected(false);
        machame.setSelected(false);
        jCheckBox19.setSelected(false);
        jDumaShort.setSelected(false);
        Simba2.setSelected(false);
        Simba3.setSelected(false);
        jCheckBox27.setSelected(false);
        GranSafari.setSelected(false);
        
        
        jCheckBox28.setSelected(false);
        ThursdayGRP.setSelected(false);
        SundayGRP.setSelected(false);
        BeachDumaShort.setSelected(false);
        jCheckBox24.setSelected(false);
        DumaPemba.setSelected(false);
        PumbaPemba.setSelected(false);
        SimbaPemba.setSelected(false);
        jCheckBox22.setSelected(false);
        machame6.setSelected(false);
        Marangu7days.setSelected(false);
        jCheckBox25.setSelected(false);
        jCheckBox26.setSelected(false);
        Lemosho10days.setSelected(false);
        jLuxDuma.setSelected(false);
        jLuxPumba.setSelected(false);
        jLuxSimba.setSelected(false);
        jDC.setSelected(false);
        jPC.setSelected(false);
        jSC.setSelected(false);
        jKC.setSelected(false);
    }//GEN-LAST:event_jButton5ActionPerformed

    private void jButton4ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jButton4ActionPerformed
        // TODO add your handling code here:

        // set customer name, i.e. folder
        String custname=jTextField1.getText();

        // set program number
        String prognum=jTextField4.getText();

        // Copy Programs in Italian lang for the checked programs
        if (jCheckBox1.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpDuma.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
        if (jDumaShort.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpDumaShort.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (jCheckBox2.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpPumba.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (jCheckBox3.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpSimba.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (jCheckBox4.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpKiboko.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (jCheckBox5.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpTembo.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (jCheckBox6.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpChui.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (jCheckBox7.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpFaru.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (jCheckBox8.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpMbogo.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
        
        if (jCheckBox9.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpGombeKatavi.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (jCheckBox11.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpGMI.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (jCheckBox12.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpGME.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
        if (jCheckBox13.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpNdege.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
        

        if (jCheckBox16.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpBeachP.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (jCheckBox17.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpBeach14.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (machame.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpMachame.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (Marangu7days.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpMarangu7days.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (jCheckBox19.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpMarangu.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
        if (jCheckBox22.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpBeachZS.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (jCheckBox24.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpBeachSimba.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
        if (jCheckBox25.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpRongai.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
        if (jCheckBox26.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpLemosho.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
        if (jCheckBox27.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpNyani.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
        if (jCheckBox28.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpBaobab.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
        
        if (DumaPemba.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpDumaPemba.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
        if (PumbaPemba.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpPumbaPemba.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
        if (SimbaPemba.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpSimbaPemba.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
        if (Simba2.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpSimba2.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
        if (Simba3.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpSimba3.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
        if (GranSafari.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpGS.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (SundayGRP.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpSG.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (ThursdayGRP.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpTG.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (machame6.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpMachame6.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
        if (BeachDumaShort.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpbeachDS.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (Lemosho10days.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpLemosho10days.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (jLuxDuma.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpLuxDuma.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (jLuxPumba.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpLuxPumba.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (jLuxSimba.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpLuxSimba.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (jDC.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpDC.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (jPC.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpPC.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (jSC.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpSC.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        if (jKC.isSelected()==true)
        try {
            String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" cpKC.bat " + custname + " " + prognum;
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }

        // Remove OPEN
        //try {
            //    String newcust="cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" remOPEN.bat " + custname;
            //    Runtime rn=Runtime.getRuntime();
            //    Process pr=rn.exec(newcust);
            //}   catch(IOException ioException) {
            //    System.out.println(ioException.getMessage() );
            //}
    }//GEN-LAST:event_jButton4ActionPerformed

    private void jCheckBox1ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jCheckBox1ActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_jCheckBox1ActionPerformed

    private void jButton3ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jButton3ActionPerformed
        jTextField1.setText("");
        resetDatesAndGrp();
    }//GEN-LAST:event_jButton3ActionPerformed

    private void resetDatesAndGrp() {
        jTextField2.setText("NA");
        jTextField3.setText("NA");
        jTextField10.setText("NA");
        jTextField10b.setText("NA");
        if (grpActionCombo != null) {
            grpActionCombo.setSelectedIndex(0);
            grpCodeField.setText("");
            grpCodeField.setEnabled(false);
        }
    }

    private void jButton2ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jButton2ActionPerformed

        // open the folder in windows explorer
        try {
            // set customer name, i.e. folder
            String custnamein=jTextField1.getText();
            String custname=custnamein.replaceAll("\\s", "");;
            String newcust="cmd /c explorer.exe \"%DROPBOX_HOME%\\%REQ_YEAR%\\\"" + custname;
            System.out.println(newcust);
            Runtime rn=Runtime.getRuntime();
            Process pr=rn.exec(newcust);
        }   catch(IOException ioException) {
            System.out.println(ioException.getMessage() );
        }
    }//GEN-LAST:event_jButton2ActionPerformed

    private void jButton1ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jButton1ActionPerformed
        if (USE_API && AppSession.isLoggedIn()) {
            // New integrated flow: API creates folder in Dropbox + DB record
            NewRequestDialog dlg = new NewRequestDialog(this, AppSession.api);
            dlg.setVisible(true);
        } else {
            // Original fallback: run NewCust.bat locally
            try {
                String custnamein = jTextField1.getText();
                String custname   = custnamein.replaceAll("\\s", "");
                String newcust    = "cmd /c start /D \"%DROPBOX_HOME%\\SavannahScripts\\\" \" \" NewCust.bat " + custname;
                System.out.println(newcust);
                Runtime rn = Runtime.getRuntime();
                Process pr = rn.exec(newcust);
            } catch (IOException ioException) {
                System.out.println(ioException.getMessage());
            }
        }
    }//GEN-LAST:event_jButton1ActionPerformed

    private void jButton14ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jButton14ActionPerformed
        String mytext1 = jTextField11.getText();
        String mytext = mytext1.replaceAll("\\s", "");
        if (mytext.isEmpty()) {
            javax.swing.JOptionPane.showMessageDialog(this,
                "Please enter a search term.", "Search",
                javax.swing.JOptionPane.WARNING_MESSAGE);
            return;
        }
        // /S = recursive, /b = bare (full path), searches 001_Safari and all subfolders
        String mycmd = "dir /S /b \"%DROPBOX_HOME%\\001_Safari\\\"*" + mytext + "*";
        java.util.List<String> results = runDirCommand(mycmd);
        showResultsPopup("Search results: " + mytext, results);
    }//GEN-LAST:event_jButton14ActionPerformed

    private void jTextField1ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jTextField1ActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_jTextField1ActionPerformed

    private void jCheckBox28ActionPerformed(java.awt.event.ActionEvent evt) {//GEN-FIRST:event_jCheckBox28ActionPerformed
        // TODO add your handling code here:
    }//GEN-LAST:event_jCheckBox28ActionPerformed

    /**
     * @param args the command line arguments
     */
    public static void main(String args[]) {
        /* Set the Nimbus look and feel */
        //<editor-fold defaultstate="collapsed" desc=" Look and feel setting code (optional) ">
        /* If Nimbus (introduced in Java SE 6) is not available, stay with the default look and feel.
         * For details see http://download.oracle.com/javase/tutorial/uiswing/lookandfeel/plaf.html 
         */
        try {
            for (javax.swing.UIManager.LookAndFeelInfo info : javax.swing.UIManager.getInstalledLookAndFeels()) {
                if ("Nimbus".equals(info.getName())) {
                    javax.swing.UIManager.setLookAndFeel(info.getClassName());
                    break;
                }
            }
        } catch (ClassNotFoundException ex) {
            java.util.logging.Logger.getLogger(BackOfficeMain.class.getName()).log(java.util.logging.Level.SEVERE, null, ex);
        } catch (InstantiationException ex) {
            java.util.logging.Logger.getLogger(BackOfficeMain.class.getName()).log(java.util.logging.Level.SEVERE, null, ex);
        } catch (IllegalAccessException ex) {
            java.util.logging.Logger.getLogger(BackOfficeMain.class.getName()).log(java.util.logging.Level.SEVERE, null, ex);
        } catch (javax.swing.UnsupportedLookAndFeelException ex) {
            java.util.logging.Logger.getLogger(BackOfficeMain.class.getName()).log(java.util.logging.Level.SEVERE, null, ex);
        }
        //</editor-fold>

        /* Create and display the form */
        java.awt.EventQueue.invokeLater(new Runnable() {
            public void run() {
                new BackOfficeMain().setVisible(true);
            }
        });
    }

    // -------------------------------------------------------------------------
    // Helper: popup Browse con ricerca, filtro real-time, navigazione e OK
    // -------------------------------------------------------------------------
    private void showBrowsePopup(String title, String rootPath) {
        final java.nio.file.Path rootDir = java.nio.file.Paths.get(rootPath);

        javax.swing.JDialog dialog = new javax.swing.JDialog(this, title, true);
        dialog.setSize(600, 540);
        dialog.setLocationRelativeTo(this);
        dialog.setLayout(new java.awt.BorderLayout(4, 4));

        // --- Barra path + pulsante Indietro ---
        javax.swing.JTextField pathField = new javax.swing.JTextField(rootPath);
        pathField.setEditable(false);
        pathField.setFont(new java.awt.Font("Monospaced", java.awt.Font.PLAIN, 11));
        pathField.setBackground(new java.awt.Color(240, 240, 240));
        javax.swing.JButton backButton = new javax.swing.JButton("◀ Indietro");
        backButton.setPreferredSize(new java.awt.Dimension(100, 26));
        backButton.setEnabled(false);
        javax.swing.JPanel pathPanel = new javax.swing.JPanel(new java.awt.BorderLayout(4, 0));
        pathPanel.setBorder(javax.swing.BorderFactory.createEmptyBorder(6, 8, 0, 8));
        pathPanel.add(new javax.swing.JLabel("Path: "), java.awt.BorderLayout.WEST);
        pathPanel.add(pathField, java.awt.BorderLayout.CENTER);
        pathPanel.add(backButton, java.awt.BorderLayout.EAST);

        // --- Campo ricerca ---
        javax.swing.JTextField searchField = new javax.swing.JTextField();
        searchField.setFont(new java.awt.Font("SansSerif", java.awt.Font.PLAIN, 13));
        javax.swing.JPanel searchPanel = new javax.swing.JPanel(new java.awt.BorderLayout(6, 0));
        searchPanel.setBorder(javax.swing.BorderFactory.createEmptyBorder(4, 8, 4, 8));
        searchPanel.add(new javax.swing.JLabel("Cerca:  "), java.awt.BorderLayout.WEST);
        searchPanel.add(searchField, java.awt.BorderLayout.CENTER);

        javax.swing.JPanel topPanel = new javax.swing.JPanel(new java.awt.BorderLayout());
        topPanel.add(pathPanel, java.awt.BorderLayout.NORTH);
        topPanel.add(searchPanel, java.awt.BorderLayout.SOUTH);

        // --- Lista ---
        javax.swing.DefaultListModel<String> model = new javax.swing.DefaultListModel<>();
        javax.swing.JList<String> list = new javax.swing.JList<>(model);
        list.setSelectionMode(javax.swing.ListSelectionModel.SINGLE_SELECTION);
        list.setFont(new java.awt.Font("Monospaced", java.awt.Font.PLAIN, 13));
        javax.swing.JScrollPane scrollPane = new javax.swing.JScrollPane(list);
        scrollPane.setBorder(javax.swing.BorderFactory.createEmptyBorder(0, 8, 0, 8));

        // --- Pulsanti OK / Annulla ---
        javax.swing.JButton okButton = new javax.swing.JButton("OK");
        javax.swing.JButton cancelButton = new javax.swing.JButton("Annulla");
        okButton.setPreferredSize(new java.awt.Dimension(90, 28));
        cancelButton.setPreferredSize(new java.awt.Dimension(90, 28));
        javax.swing.JLabel hint = new javax.swing.JLabel(
            "  \uD83D\uDCC1 doppio click = entra nella cartella");
        hint.setFont(hint.getFont().deriveFont(java.awt.Font.ITALIC, 11f));
        javax.swing.JPanel bottomPanel = new javax.swing.JPanel(new java.awt.BorderLayout(6, 0));
        bottomPanel.setBorder(javax.swing.BorderFactory.createEmptyBorder(4, 8, 8, 8));
        javax.swing.JPanel btnPanel = new javax.swing.JPanel(new java.awt.FlowLayout(java.awt.FlowLayout.RIGHT, 8, 0));
        btnPanel.add(okButton);
        btnPanel.add(cancelButton);
        bottomPanel.add(hint, java.awt.BorderLayout.WEST);
        bottomPanel.add(btnPanel, java.awt.BorderLayout.EAST);

        dialog.add(topPanel,    java.awt.BorderLayout.NORTH);
        dialog.add(scrollPane,  java.awt.BorderLayout.CENTER);
        dialog.add(bottomPanel, java.awt.BorderLayout.SOUTH);

        // Stato navigazione
        final java.nio.file.Path[] currentDir = { rootDir };
        final String[][] allDisplayNames = { new String[0] };

        Runnable loadDir = () -> {
            searchField.setText("");
            java.io.File folder = currentDir[0].toFile();
            java.io.File[] files = folder.listFiles();
            if (files == null) files = new java.io.File[0];
            java.util.Arrays.sort(files, (a, b) -> {
                if (a.isDirectory() != b.isDirectory()) return a.isDirectory() ? -1 : 1;
                return a.getName().compareToIgnoreCase(b.getName());
            });
            allDisplayNames[0] = new String[files.length];
            for (int i = 0; i < files.length; i++)
                allDisplayNames[0][i] = (files[i].isDirectory() ? "\uD83D\uDCC1 " : "   ") + files[i].getName();
            javax.swing.DefaultListModel<String> nm = new javax.swing.DefaultListModel<>();
            for (String s : allDisplayNames[0]) nm.addElement(s);
            list.setModel(nm);
            pathField.setText(currentDir[0].toString());
            backButton.setEnabled(!currentDir[0].equals(rootDir));
            if (list.getModel().getSize() > 0) list.setSelectedIndex(0);
            searchField.requestFocusInWindow();
        };

        loadDir.run();

        // Filtro real-time
        searchField.getDocument().addDocumentListener(new javax.swing.event.DocumentListener() {
            private void filter() {
                String text = searchField.getText().toLowerCase();
                javax.swing.DefaultListModel<String> fm = new javax.swing.DefaultListModel<>();
                for (String s : allDisplayNames[0]) {
                    String name = s.startsWith("\uD83D\uDCC1 ") ? s.substring(3) : s.trim();
                    if (name.toLowerCase().contains(text)) fm.addElement(s);
                }
                list.setModel(fm);
                if (fm.getSize() > 0) list.setSelectedIndex(0);
            }
            public void insertUpdate(javax.swing.event.DocumentEvent e) { filter(); }
            public void removeUpdate(javax.swing.event.DocumentEvent e) { filter(); }
            public void changedUpdate(javax.swing.event.DocumentEvent e) { filter(); }
        });

        // Selezione voce (helper)
        Runnable selectCurrent = () -> {
            String sel = list.getSelectedValue();
            if (sel == null && list.getModel().getSize() > 0)
                sel = list.getModel().getElementAt(0);
            if (sel == null) return;
            boolean isDir = sel.startsWith("\uD83D\uDCC1 ");
            String name = isDir ? sel.substring(3).trim() : sel.trim();
            if (isDir) {
                currentDir[0] = currentDir[0].resolve(name);
                loadDir.run();
            } else {
                jTextField1.setText(name);
                resetDatesAndGrp();
                dialog.dispose();
            }
        };

        // Doppio click
        list.addMouseListener(new java.awt.event.MouseAdapter() {
            @Override
            public void mouseClicked(java.awt.event.MouseEvent e) {
                if (e.getClickCount() == 2) selectCurrent.run();
            }
        });

        // Pulsante OK — usa la selezione corrente senza navigare nelle cartelle
        okButton.addActionListener(e -> {
            String sel = list.getSelectedValue();
            if (sel == null) return;
            String name = sel.startsWith("\uD83D\uDCC1 ") ? sel.substring(3).trim() : sel.trim();
            jTextField1.setText(name);
            resetDatesAndGrp();
            dialog.dispose();
        });

        // Pulsante Annulla
        cancelButton.addActionListener(e -> dialog.dispose());

        // Pulsante Indietro
        backButton.addActionListener(e -> {
            java.nio.file.Path parent = currentDir[0].getParent();
            if (parent != null && currentDir[0].startsWith(rootDir) && !currentDir[0].equals(rootDir)) {
                currentDir[0] = parent;
                loadDir.run();
            }
        });

        // Invio nel campo ricerca = doppio click sul primo risultato
        searchField.addActionListener(e -> selectCurrent.run());

        dialog.getRootPane().setDefaultButton(okButton);

        dialog.addWindowListener(new java.awt.event.WindowAdapter() {
            @Override public void windowOpened(java.awt.event.WindowEvent e) {
                searchField.requestFocusInWindow();
            }
        });

        dialog.setVisible(true);
    }

    // -------------------------------------------------------------------------
    // Helper: ricerca ricorsiva per nome file/cartella in una directory
    // -------------------------------------------------------------------------
    private void searchRecursive(java.io.File folder, String keyword, java.util.List<String> results) {
        java.io.File[] files = folder.listFiles();
        if (files == null) return;
        for (java.io.File f : files) {
            if (f.getName().toLowerCase().contains(keyword)) {
                results.add(f.getAbsolutePath());
            }
            if (f.isDirectory()) {
                searchRecursive(f, keyword, results);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helper: esegue un comando dir e restituisce i risultati come lista
    // -------------------------------------------------------------------------
    private java.util.List<String> runDirCommand(String dirCmd) {
        java.util.List<String> results = new java.util.ArrayList<>();
        try {
            ProcessBuilder pb = new ProcessBuilder("cmd", "/c", dirCmd);
            pb.redirectErrorStream(true);
            Process pr = pb.start();
            java.io.BufferedReader reader = new java.io.BufferedReader(
                new java.io.InputStreamReader(pr.getInputStream()));
            String line;
            while ((line = reader.readLine()) != null) {
                line = line.trim();
                if (!line.isEmpty()) {
                    results.add(line);
                }
            }
            pr.waitFor();
        } catch (IOException | InterruptedException e) {
            System.out.println(e.getMessage());
        }
        return results;
    }

    // -------------------------------------------------------------------------
    // Helper: mostra i risultati in un popup; doppio click popola Customer File
    // -------------------------------------------------------------------------
    private void showResultsPopup(String title, java.util.List<String> results) {
        if (results.isEmpty()) {
            javax.swing.JOptionPane.showMessageDialog(
                this, "No results found.", title,
                javax.swing.JOptionPane.INFORMATION_MESSAGE);
            return;
        }

        javax.swing.JDialog dialog = new javax.swing.JDialog(this, title, true);
        dialog.setSize(520, 380);
        dialog.setLocationRelativeTo(this);
        dialog.setLayout(new java.awt.BorderLayout(0, 4));

        javax.swing.DefaultListModel<String> model = new javax.swing.DefaultListModel<>();
        for (String r : results) model.addElement(r);

        javax.swing.JList<String> list = new javax.swing.JList<>(model);
        list.setSelectionMode(javax.swing.ListSelectionModel.SINGLE_SELECTION);
        list.setFont(new java.awt.Font("Monospaced", java.awt.Font.PLAIN, 13));

        list.addMouseListener(new java.awt.event.MouseAdapter() {
            @Override
            public void mouseClicked(java.awt.event.MouseEvent e) {
                if (e.getClickCount() == 2) {
                    String selected = list.getSelectedValue();
                    if (selected != null) {
                        String result;
                        java.io.File f = new java.io.File(selected);
                        if (f.isAbsolute()) {
                            // Full path (from dir /S /b): strip filename if it's a file
                            String folderPath = f.isDirectory() ? selected : f.getParent();
                            if (folderPath == null) folderPath = selected;
                            // Keep only the part after 001_Safari
                            String marker = "001_safari";
                            int idx = folderPath.toLowerCase().indexOf(marker);
                            if (idx >= 0) {
                                folderPath = folderPath.substring(idx + marker.length());
                                if (folderPath.startsWith("\\") || folderPath.startsWith("/")) {
                                    folderPath = folderPath.substring(1);
                                }
                            }
                            result = folderPath;
                        } else {
                            // Bare name (from dir /b): use directly
                            result = selected;
                        }
                        jTextField1.setText(result);
                        resetDatesAndGrp();
                        dialog.dispose();
                    }
                }
            }
        });

        javax.swing.JScrollPane scrollPane = new javax.swing.JScrollPane(list);
        scrollPane.setBorder(javax.swing.BorderFactory.createEmptyBorder(4, 6, 4, 6));

        javax.swing.JLabel hint = new javax.swing.JLabel(
            "  Double-click to select and populate Customer File");
        hint.setFont(hint.getFont().deriveFont(java.awt.Font.ITALIC, 11f));
        hint.setBorder(javax.swing.BorderFactory.createEmptyBorder(2, 6, 6, 6));

        dialog.add(scrollPane, java.awt.BorderLayout.CENTER);
        dialog.add(hint, java.awt.BorderLayout.SOUTH);
        dialog.setVisible(true);
    }

    // -------------------------------------------------------------------------
    /** showResultsPopup with optional Word export button (used by Missing CK) */
    private void showResultsPopup(String title, java.util.List<String> results, boolean showExport) {
        if (!showExport) { showResultsPopup(title, results); return; }

        if (results.isEmpty()) {
            javax.swing.JOptionPane.showMessageDialog(
                this, "No results found.", title,
                javax.swing.JOptionPane.INFORMATION_MESSAGE);
            return;
        }

        javax.swing.JDialog dialog = new javax.swing.JDialog(this, title, true);
        dialog.setSize(560, 460);
        dialog.setLocationRelativeTo(this);
        dialog.setLayout(new java.awt.BorderLayout(0, 4));

        // Track which row indices are agent-header rows (non-selectable)
        final java.util.Set<Integer> headerRows = new java.util.HashSet<>();

        javax.swing.DefaultListModel<String> model = new javax.swing.DefaultListModel<>();
        for (String r : results) model.addElement(r);

        javax.swing.JList<String> list = new javax.swing.JList<>(model);
        list.setSelectionMode(javax.swing.ListSelectionModel.SINGLE_SELECTION);
        list.setFont(new java.awt.Font("Monospaced", java.awt.Font.PLAIN, 13));

        // Custom renderer: agent headers get bold + tinted background, folder rows get indent
        list.setCellRenderer(new javax.swing.DefaultListCellRenderer() {
            @Override
            public java.awt.Component getListCellRendererComponent(
                    javax.swing.JList<?> l, Object value, int index,
                    boolean isSelected, boolean cellHasFocus) {
                super.getListCellRendererComponent(l, value, index, isSelected, cellHasFocus);
                if (headerRows.contains(index)) {
                    setFont(new java.awt.Font("SansSerif", java.awt.Font.BOLD, 12));
                    setBackground(new java.awt.Color(220, 235, 220));
                    setForeground(new java.awt.Color(30, 90, 30));
                    setBorder(javax.swing.BorderFactory.createEmptyBorder(3, 6, 3, 6));
                } else {
                    setFont(new java.awt.Font("Monospaced", java.awt.Font.PLAIN, 13));
                    if (!isSelected) {
                        setBackground(java.awt.Color.WHITE);
                        setForeground(java.awt.Color.BLACK);
                    }
                    setBorder(javax.swing.BorderFactory.createEmptyBorder(1, 18, 1, 6));
                }
                return this;
            }
        });

        // Rebuild model — flat view
        Runnable buildFlat = () -> {
            headerRows.clear();
            model.clear();
            for (String r : results) model.addElement(r);
        };

        // Cache for the agent→folder map fetched by the SwingWorker
        // (single-element array trick to allow mutation inside lambdas)
        @SuppressWarnings("unchecked")
        final java.util.Map<String, String>[] agentMapRef = new java.util.Map[]{null};

        // Helper: populate model from agent→folders map (must run on EDT)
        java.util.function.Consumer<java.util.Map<String, String>> applyGrouped = (folderAgentMap) -> {
            agentMapRef[0] = folderAgentMap;
            headerRows.clear();
            model.clear();
            java.util.LinkedHashMap<String, java.util.List<String>> byAgent = new java.util.LinkedHashMap<>();
            for (String r : results) {
                String agent = folderAgentMap.getOrDefault(r, "Unknown");
                byAgent.computeIfAbsent(agent, k -> new java.util.ArrayList<>()).add(r);
            }
            java.util.List<String> agentsSorted = new java.util.ArrayList<>(byAgent.keySet());
            java.util.Collections.sort(agentsSorted, String.CASE_INSENSITIVE_ORDER);
            int idx = 0;
            for (String agent : agentsSorted) {
                java.util.List<String> folders = byAgent.get(agent);
                model.addElement("── " + agent + " (" + folders.size() + ")");
                headerRows.add(idx++);
                for (String f : folders) {
                    model.addElement(f);
                    idx++;
                }
            }
        };

        // Rebuild model — grouped by agent via API lookup
        Runnable buildGrouped = () -> {
            // Show loading placeholder on EDT
            headerRows.clear();
            model.clear();
            model.addElement("  Loading agent data…");

            new javax.swing.SwingWorker<java.util.Map<String, String>, Void>() {
                @Override
                protected java.util.Map<String, String> doInBackground() {
                    return fetchFolderAgents(results);
                }
                @Override
                protected void done() {
                    try {
                        java.util.Map<String, String> map = get();
                        applyGrouped.accept(map);
                    } catch (Exception ex) {
                        // Fallback: string parsing
                        java.util.Map<String, String> fallback = new java.util.HashMap<>();
                        for (String r : results) fallback.put(r, extractAgent(r));
                        applyGrouped.accept(fallback);
                    }
                }
            }.execute();
        };

        list.addMouseListener(new java.awt.event.MouseAdapter() {
            @Override
            public void mouseClicked(java.awt.event.MouseEvent e) {
                if (e.getClickCount() == 2) {
                    int row = list.getSelectedIndex();
                    if (row < 0 || headerRows.contains(row)) return; // skip headers
                    String selected = list.getSelectedValue();
                    if (selected == null) return;
                    // Strip leading indent spaces added in flat/grouped modes
                    selected = selected.trim();
                    String result;
                    java.io.File f = new java.io.File(selected);
                    if (f.isAbsolute()) {
                        String folderPath = f.isDirectory() ? selected : f.getParent();
                        if (folderPath == null) folderPath = selected;
                        String marker = "001_safari";
                        int idx = folderPath.toLowerCase().indexOf(marker);
                        if (idx >= 0) {
                            folderPath = folderPath.substring(idx + marker.length());
                            if (folderPath.startsWith("\\") || folderPath.startsWith("/"))
                                folderPath = folderPath.substring(1);
                        }
                        result = folderPath;
                    } else {
                        result = selected;
                    }
                    jTextField1.setText(result);
                    dialog.dispose();
                }
            }
        });

        // Prevent selection of header rows
        list.addListSelectionListener(e -> {
            if (!e.getValueIsAdjusting()) {
                int row = list.getSelectedIndex();
                if (row >= 0 && headerRows.contains(row))
                    list.clearSelection();
            }
        });

        javax.swing.JScrollPane scrollPane = new javax.swing.JScrollPane(list);
        scrollPane.setBorder(javax.swing.BorderFactory.createEmptyBorder(4, 6, 4, 6));

        // ── Checkbox ──────────────────────────────────────────────────────────
        javax.swing.JCheckBox groupChk = new javax.swing.JCheckBox("Grouped by Agent");
        groupChk.setFont(groupChk.getFont().deriveFont(12f));
        groupChk.addItemListener(e -> {
            if (e.getStateChange() == java.awt.event.ItemEvent.SELECTED) buildGrouped.run();
            else buildFlat.run();
        });

        // ── Hint label ────────────────────────────────────────────────────────
        javax.swing.JLabel hint = new javax.swing.JLabel("  Double-click to select and populate Customer File");
        hint.setFont(hint.getFont().deriveFont(java.awt.Font.ITALIC, 11f));

        // ── Export button ─────────────────────────────────────────────────────
        javax.swing.JButton exportBtn = new javax.swing.JButton("⬇  Download as Word (.docx)");
        exportBtn.addActionListener(ev -> {
            javax.swing.JFileChooser fc = new javax.swing.JFileChooser();
            fc.setDialogTitle("Save Missing CK list");
            fc.setSelectedFile(new java.io.File("Missing_CK_" +
                new java.text.SimpleDateFormat("yyyyMMdd").format(new java.util.Date()) + ".docx"));
            fc.setFileFilter(new javax.swing.filechooser.FileNameExtensionFilter("Word Document (*.docx)", "docx"));
            if (fc.showSaveDialog(dialog) == javax.swing.JFileChooser.APPROVE_OPTION) {
                java.io.File out = fc.getSelectedFile();
                if (!out.getName().toLowerCase().endsWith(".docx"))
                    out = new java.io.File(out.getAbsolutePath() + ".docx");
                try {
                    exportMissingCKDocx(title, results, groupChk.isSelected(), agentMapRef[0], out);
                    javax.swing.JOptionPane.showMessageDialog(dialog,
                        "File saved:\n" + out.getAbsolutePath(),
                        "Export complete", javax.swing.JOptionPane.INFORMATION_MESSAGE);
                } catch (Exception ex) {
                    javax.swing.JOptionPane.showMessageDialog(dialog,
                        "Export failed: " + ex.getMessage(),
                        "Error", javax.swing.JOptionPane.ERROR_MESSAGE);
                }
            }
        });

        // ── South panel: 3 rows ───────────────────────────────────────────────
        javax.swing.JPanel south = new javax.swing.JPanel();
        south.setLayout(new java.awt.GridLayout(3, 1, 0, 2));
        south.setBorder(javax.swing.BorderFactory.createEmptyBorder(2, 8, 8, 8));

        javax.swing.JPanel row1 = new javax.swing.JPanel(new java.awt.FlowLayout(java.awt.FlowLayout.LEFT, 0, 0));
        row1.add(groupChk);

        javax.swing.JPanel row2 = new javax.swing.JPanel(new java.awt.FlowLayout(java.awt.FlowLayout.LEFT, 0, 0));
        row2.add(hint);

        javax.swing.JPanel row3 = new javax.swing.JPanel(new java.awt.FlowLayout(java.awt.FlowLayout.RIGHT, 0, 0));
        row3.add(exportBtn);

        south.add(row1);
        south.add(row2);
        south.add(row3);

        dialog.add(scrollPane, java.awt.BorderLayout.CENTER);
        dialog.add(south, java.awt.BorderLayout.SOUTH);
        dialog.setVisible(true);
    }

    // -------------------------------------------------------------------------
    /** Extracts the agent name from a Dropbox folder name (fallback — string parsing).
     *  Convention: MM_CustomerName(AgentFirstName-...) — first token inside () */
    private String extractAgent(String folderName) {
        int open  = folderName.indexOf('(');
        int close = folderName.lastIndexOf(')');
        if (open < 0 || close <= open) return "Unknown";
        String inside = folderName.substring(open + 1, close).trim();
        int dash = inside.indexOf('-');
        String first = (dash >= 0 ? inside.substring(0, dash) : inside).trim();
        return first.isEmpty() ? "Unknown" : first;
    }

    // -------------------------------------------------------------------------
    /** Calls api_folder_agents.php with a list of folder names (practice_code)
     *  and returns a map { folderName -> agentName } from the DB.
     *  Folders not found in DB are absent from the map (caller uses "Unknown"). */
    private java.util.Map<String, String> fetchFolderAgents(java.util.List<String> folders) {
        java.util.Map<String, String> result = new java.util.HashMap<>();
        if (folders == null || folders.isEmpty()) return result;
        try {
            // Build JSON body: {"folders":["name1","name2",...]}
            StringBuilder json = new StringBuilder("{\"folders\":[");
            for (int i = 0; i < folders.size(); i++) {
                if (i > 0) json.append(',');
                json.append('"').append(jsonStringEscape(folders.get(i))).append('"');
            }
            json.append("]}");

            String resp = postApiDirect("api_folder_agents.php", json.toString());
            // Parse simple flat JSON object: {"key":"value","key2":"value2",...}
            // Using indexOf — no external JSON lib needed
            int i = 0;
            while (true) {
                int q1 = resp.indexOf('"', i);
                if (q1 < 0) break;
                int q2 = resp.indexOf('"', q1 + 1);
                if (q2 < 0) break;
                String key = resp.substring(q1 + 1, q2);
                int colon = resp.indexOf(':', q2 + 1);
                if (colon < 0) break;
                int q3 = resp.indexOf('"', colon + 1);
                if (q3 < 0) break;
                int q4 = resp.indexOf('"', q3 + 1);
                if (q4 < 0) break;
                String value = resp.substring(q3 + 1, q4);
                if (!key.isEmpty()) result.put(key, value);
                i = q4 + 1;
            }
        } catch (Exception ignored) {}
        return result;
    }

    /** Escapes a string for embedding inside a JSON double-quoted value. */
    private String jsonStringEscape(String s) {
        if (s == null) return "";
        return s.replace("\\", "\\\\").replace("\"", "\\\"")
                .replace("\n", "\\n").replace("\r", "\\r").replace("\t", "\\t");
    }

    // -------------------------------------------------------------------------
    /** Generates a minimal .docx (ZIP + XML) with the Missing CK folder list.
     *  No external libraries required — uses java.util.zip only.
     *  When grouped=true, items are sorted and printed under agent headings. */
    private void exportMissingCKDocx(String title, java.util.List<String> items,
                                     boolean grouped, java.util.Map<String, String> agentMap,
                                     java.io.File dest)
            throws java.io.IOException {

        StringBuilder body = new StringBuilder();

        // ── Title ──────────────────────────────────────────────────────────
        body.append("<w:p><w:pPr><w:pStyle w:val=\"Heading1\"/></w:pPr>")
            .append("<w:r><w:t>").append(xmlEscape(title)).append("</w:t></w:r></w:p>");

        // ── Date subtitle ──────────────────────────────────────────────────
        String dateStr = new java.text.SimpleDateFormat("dd MMMM yyyy", java.util.Locale.ENGLISH)
            .format(new java.util.Date());
        body.append("<w:p>")
            .append("<w:r><w:rPr><w:color w:val=\"888888\"/><w:sz w:val=\"20\"/></w:rPr>")
            .append("<w:t>Generated: ").append(xmlEscape(dateStr)).append("</w:t></w:r></w:p>");

        // ── Spacer ─────────────────────────────────────────────────────────
        body.append("<w:p><w:r><w:t></w:t></w:r></w:p>");

        if (!grouped) {
            // ── Flat list ──────────────────────────────────────────────────
            for (int i = 0; i < items.size(); i++) {
                body.append("<w:p>")
                    .append("<w:r><w:rPr><w:rFonts w:ascii=\"Courier New\" w:hAnsi=\"Courier New\"/>")
                    .append("<w:sz w:val=\"20\"/></w:rPr>")
                    .append("<w:t xml:space=\"preserve\">").append(i + 1).append(".  ")
                    .append(xmlEscape(items.get(i))).append("</w:t></w:r></w:p>");
            }
        } else {
            // ── Grouped by agent ───────────────────────────────────────────
            java.util.LinkedHashMap<String, java.util.List<String>> byAgent = new java.util.LinkedHashMap<>();
            for (String r : items) {
                String agent = (agentMap != null && agentMap.containsKey(r))
                    ? agentMap.get(r) : extractAgent(r);
                byAgent.computeIfAbsent(agent, k -> new java.util.ArrayList<>()).add(r);
            }
            java.util.List<String> agentsSorted = new java.util.ArrayList<>(byAgent.keySet());
            java.util.Collections.sort(agentsSorted, String.CASE_INSENSITIVE_ORDER);

            for (String agent : agentsSorted) {
                java.util.List<String> folders = byAgent.get(agent);

                // Agent heading (Heading2 style, green shade)
                body.append("<w:p><w:pPr><w:pStyle w:val=\"Heading2\"/>")
                    .append("<w:shd w:val=\"clear\" w:color=\"auto\" w:fill=\"DCEEDD\"/></w:pPr>")
                    .append("<w:r><w:t xml:space=\"preserve\">")
                    .append(xmlEscape(agent))
                    .append("  (").append(folders.size()).append(")")
                    .append("</w:t></w:r></w:p>");

                // Folders under this agent
                for (int i = 0; i < folders.size(); i++) {
                    body.append("<w:p>")
                        .append("<w:pPr><w:ind w:left=\"360\"/></w:pPr>")
                        .append("<w:r><w:rPr><w:rFonts w:ascii=\"Courier New\" w:hAnsi=\"Courier New\"/>")
                        .append("<w:sz w:val=\"20\"/></w:rPr>")
                        .append("<w:t xml:space=\"preserve\">").append(i + 1).append(".  ")
                        .append(xmlEscape(folders.get(i))).append("</w:t></w:r></w:p>");
                }
                // Small spacer between groups
                body.append("<w:p><w:r><w:t></w:t></w:r></w:p>");
            }
        }

        // ── Final empty paragraph ──────────────────────────────────────────
        body.append("<w:p><w:r><w:t></w:t></w:r></w:p>");

        String documentXml =
            "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>" +
            "<w:document xmlns:wpc=\"http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas\" " +
            "xmlns:w=\"http://schemas.openxmlformats.org/wordprocessingml/2006/main\" " +
            "xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\">" +
            "<w:body>" + body + "</w:body></w:document>";

        String contentTypes =
            "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>" +
            "<Types xmlns=\"http://schemas.openxmlformats.org/package/2006/content-types\">" +
            "<Default Extension=\"rels\" ContentType=\"application/vnd.openxmlformats-package.relationships+xml\"/>" +
            "<Default Extension=\"xml\" ContentType=\"application/xml\"/>" +
            "<Override PartName=\"/word/document.xml\" " +
            "ContentType=\"application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml\"/>" +
            "</Types>";

        String relsMain =
            "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>" +
            "<Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">" +
            "<Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument\" " +
            "Target=\"word/document.xml\"/>" +
            "</Relationships>";

        String wordRels =
            "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>" +
            "<Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">" +
            "</Relationships>";

        try (java.util.zip.ZipOutputStream zos =
                new java.util.zip.ZipOutputStream(new java.io.FileOutputStream(dest))) {
            putZipEntry(zos, "[Content_Types].xml", contentTypes);
            putZipEntry(zos, "_rels/.rels", relsMain);
            putZipEntry(zos, "word/document.xml", documentXml);
            putZipEntry(zos, "word/_rels/document.xml.rels", wordRels);
        }
    }

    private void putZipEntry(java.util.zip.ZipOutputStream zos, String name, String content)
            throws java.io.IOException {
        zos.putNextEntry(new java.util.zip.ZipEntry(name));
        zos.write(content.getBytes(java.nio.charset.StandardCharsets.UTF_8));
        zos.closeEntry();
    }

    private String xmlEscape(String s) {
        if (s == null) return "";
        return s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
                .replace("\"", "&quot;").replace("'", "&apos;");
    }

    // -------------------------------------------------------------------------
    /** Recursively apply fonts to all components in a container */
    private void applyFonts(java.awt.Container container, java.awt.Font base, java.awt.Font bold) {
        for (java.awt.Component c : container.getComponents()) {
            if (c instanceof javax.swing.JLabel)     c.setFont(base);
            if (c instanceof javax.swing.JCheckBox)  c.setFont(base);
            if (c instanceof javax.swing.JComboBox)  c.setFont(base);
            if (c instanceof javax.swing.JTextField) c.setFont(base);
            if (c instanceof javax.swing.JButton) {
                c.setFont(bold);
            }
            if (c instanceof java.awt.Container) applyFonts((java.awt.Container) c, base, bold);
        }
    }

    // -------------------------------------------------------------------------
    // Scans the customer's Dropbox folder for files whose names start with
    // a number, finds the maximum, and sets Prog Number to max+1.
    // Searches 2026/ first; if not found there, tries 001_Safari/.
    // Only updates the field if the user hasn't manually overridden it
    // ── ADD GRP worker: move customer folder from REQ_YEAR into GRP parent ──────
    private void launchAddGrpWorker(String custname, String grpMainFolder) {
        jButton11.setEnabled(false);
        jButton11.setText("Working…");

        javax.swing.JDialog dlg = new javax.swing.JDialog(this, "Add to GRP", false);
        dlg.setLayout(new java.awt.BorderLayout());
        javax.swing.JPanel hdr = new javax.swing.JPanel(new java.awt.FlowLayout(java.awt.FlowLayout.LEFT, 14, 10));
        hdr.setBackground(new java.awt.Color(0x1A1A2E));
        javax.swing.JLabel hdrLbl = new javax.swing.JLabel("Add to GRP — " + grpMainFolder);
        hdrLbl.setFont(new java.awt.Font("SansSerif", java.awt.Font.BOLD, 13));
        hdrLbl.setForeground(java.awt.Color.WHITE);
        hdr.add(hdrLbl);
        dlg.add(hdr, java.awt.BorderLayout.NORTH);

        javax.swing.JPanel body = new javax.swing.JPanel(new java.awt.BorderLayout(8, 8));
        body.setBorder(javax.swing.BorderFactory.createEmptyBorder(16, 16, 8, 16));
        body.setBackground(java.awt.Color.WHITE);
        javax.swing.JProgressBar bar = new javax.swing.JProgressBar();
        bar.setIndeterminate(true);
        bar.setPreferredSize(new java.awt.Dimension(460, 18));
        javax.swing.JTextArea detail = new javax.swing.JTextArea();
        detail.setEditable(false);
        detail.setFont(new java.awt.Font("Monospaced", java.awt.Font.PLAIN, 12));
        detail.setBackground(new java.awt.Color(245, 245, 245));
        javax.swing.JScrollPane detailScroll = new javax.swing.JScrollPane(detail);
        detailScroll.setPreferredSize(new java.awt.Dimension(460, 120));
        detailScroll.setVisible(false);
        body.add(bar,         java.awt.BorderLayout.CENTER);
        body.add(detailScroll, java.awt.BorderLayout.SOUTH);
        dlg.add(body, java.awt.BorderLayout.CENTER);

        javax.swing.JPanel foot = new javax.swing.JPanel(new java.awt.FlowLayout(java.awt.FlowLayout.RIGHT, 10, 8));
        foot.setBackground(new java.awt.Color(0xF5F5F5));
        javax.swing.JButton closeBtn = new javax.swing.JButton("Close");
        closeBtn.setFont(new java.awt.Font("SansSerif", java.awt.Font.BOLD, 12));
        closeBtn.setEnabled(false);
        closeBtn.addActionListener(ev -> dlg.dispose());
        foot.add(closeBtn);
        dlg.add(foot, java.awt.BorderLayout.SOUTH);
        dlg.setSize(500, 200);
        dlg.setLocationRelativeTo(this);
        dlg.setDefaultCloseOperation(javax.swing.JDialog.DO_NOTHING_ON_CLOSE);
        dlg.setVisible(true);

        new SwingWorker<String, Void>() {
            @Override
            protected String doInBackground() throws Exception {
                String dropboxHome = System.getenv("DROPBOX_HOME");
                String reqYear     = System.getenv("REQ_YEAR");
                if (dropboxHome == null) dropboxHome = "";
                if (reqYear    == null) reqYear      = "2026";
                StringBuilder log = new StringBuilder();

                // Look for the folder in REQ_YEAR first, then in 001_Safari root
                // (it may already be confirmed and sitting in 001_Safari)
                String srcYear   = dropboxHome + "\\" + reqYear + "\\" + custname;
                String srcSafari = dropboxHome + "\\001_Safari\\" + custname;
                String src;
                if (new java.io.File(srcYear).exists()) {
                    src = srcYear;
                } else if (new java.io.File(srcSafari).exists()) {
                    src = srcSafari;
                } else {
                    return "ERROR:Folder not found:\n  " + srcYear + "\n  " + srcSafari;
                }
                String dstPar  = dropboxHome + "\\001_Safari\\" + grpMainFolder;
                ProcessBuilder pb = new ProcessBuilder("cmd", "/c", "move", src, dstPar);
                pb.redirectErrorStream(true);
                Process pr = pb.start();
                try (BufferedReader r = new BufferedReader(new InputStreamReader(pr.getInputStream()))) {
                    String ln; while ((ln = r.readLine()) != null) if (!ln.isBlank()) log.append(ln).append("\n");
                }
                int exit = pr.waitFor();
                if (exit != 0)
                    return "ERROR:Move failed (exit " + exit + ")" + (log.length() > 0 ? ":\n" + log : "");
                log.append("✔ Moved to ").append(grpMainFolder).append("\n");

                Thread.sleep(10_000);

                java.io.File dst = new java.io.File(dstPar + "\\" + custname);
                if (!dst.exists())
                    return "ERROR:Folder not found at destination: " + dst.getAbsolutePath();
                log.append("✔ Verified at destination\n");

                if (USE_API && AppSession.isLoggedIn()) {
                    try {
                        String body2 = "{\"old_folder_name\":\"" + escJsonS(custname)
                                     + "\",\"new_folder_name\":\"" + escJsonS(custname)
                                     + "\",\"grp_action\":\"ADD\""
                                     + ",\"grp_main_folder\":\"" + escJsonS(grpMainFolder) + "\"}";
                        String resp = postApiDirect("api_confirm_safari.php", body2);
                        log.append(resp != null && resp.contains("\"success\":true")
                            ? "✔ DB updated: status → Booked\n"
                            : "⚠ DB update: " + resp + "\n");
                    } catch (Exception ex) {
                        log.append("⚠ DB update error: ").append(ex.getMessage()).append("\n");
                    }
                }
                return "OK:" + log;
            }

            @Override
            protected void done() {
                jButton11.setEnabled(true);
                jButton11.setText("Confirm Safari");
                bar.setIndeterminate(false); bar.setValue(100);
                detailScroll.setVisible(true);
                closeBtn.setEnabled(true);
                dlg.setDefaultCloseOperation(javax.swing.JDialog.DISPOSE_ON_CLOSE);
                try {
                    String res = get();
                    detail.setText(res.startsWith("OK:") ? res.substring(3)
                                 : res.startsWith("ERROR:") ? res.substring(6) : res);
                } catch (Exception ex) { detail.setText("Error: " + ex.getMessage()); }
                dlg.setSize(500, 320);
                dlg.revalidate(); dlg.repaint();
            }
        }.execute();
    }

    private static String escJsonS(String s) {
        if (s == null) return "";
        return s.replace("\\", "\\\\").replace("\"", "\\\"");
    }

    // (we never overwrite a value the user has explicitly typed — we only
    //  auto-fill when the field still shows its last auto-computed value).
    // -------------------------------------------------------------------------
    private int lastAutoProgNum = -1; // tracks the last value we auto-set

    private void autoScanProgNumber() {
        String folderName = jTextField1.getText().trim();
        if (folderName.isEmpty()) return;

        String dropboxHome = System.getenv("DROPBOX_HOME");
        String reqYear     = System.getenv("REQ_YEAR");
        if (dropboxHome == null) return;
        if (reqYear    == null) reqYear = "2026";

        // Try year folder first, then 001_Safari
        java.io.File folder = new java.io.File(dropboxHome + "\\" + reqYear + "\\" + folderName);
        if (!folder.exists() || !folder.isDirectory()) {
            folder = new java.io.File(dropboxHome + "\\001_Safari\\" + folderName);
        }
        if (!folder.exists() || !folder.isDirectory()) return;

        java.io.File[] files = folder.listFiles(java.io.File::isFile);
        int maxNum = 0;
        if (files != null) {
            for (java.io.File f : files) {
                java.util.regex.Matcher m =
                    java.util.regex.Pattern.compile("^(\\d+)").matcher(f.getName());
                if (m.find()) {
                    try {
                        int n = Integer.parseInt(m.group(1));
                        if (n > maxNum) maxNum = n;
                    } catch (NumberFormatException ignored) {}
                }
            }
        }

        final int nextNum = maxNum + 1; // 1 if no numbered files found

        // Only update if the current value equals the last auto-set value,
        // OR if Customer Request just changed (lastAutoProgNum reset to -1 signals "always update").
        javax.swing.SwingUtilities.invokeLater(() -> {
            jTextField4.setText(String.format("%02d", nextNum));
            lastAutoProgNum = nextNum;
        });
    }

    // -------------------------------------------------------------------------
    // Direct HTTP POST to a leads-module API endpoint with a 30-second timeout.
    // Derives the URL from LEADS_LOOKUP_URL (replaces "lookup.php").
    // Returns the raw response body as a String.
    // -------------------------------------------------------------------------
    private static String getApiDirect(String endpoint) throws Exception {
        String base = LEADS_LOOKUP_URL;
        int lastSlash = base.lastIndexOf('/');
        String endpointUrl = (lastSlash >= 0 ? base.substring(0, lastSlash + 1) : base + "/") + endpoint;
        java.net.URL url = new java.net.URL(endpointUrl);
        java.net.HttpURLConnection conn = (java.net.HttpURLConnection) url.openConnection();
        conn.setRequestMethod("GET");
        conn.setConnectTimeout(10_000);
        conn.setReadTimeout(15_000);
        conn.setRequestProperty("X-API-Key", API_KEY_VALUE);
        int code = conn.getResponseCode();
        java.io.InputStream is = (code >= 200 && code < 300) ? conn.getInputStream() : conn.getErrorStream();
        if (is == null) return "[]";
        try (BufferedReader br = new BufferedReader(
                new InputStreamReader(is, java.nio.charset.StandardCharsets.UTF_8))) {
            StringBuilder sb = new StringBuilder();
            String line;
            while ((line = br.readLine()) != null) sb.append(line);
            return sb.toString();
        } finally { conn.disconnect(); }
    }

    /** Returns sorted list of active agent names from DB, or empty list on failure. */
    private java.util.List<String> fetchActiveAgentNames() {
        java.util.List<String> names = new java.util.ArrayList<>();
        if (!USE_API || !AppSession.isLoggedIn()) return names;
        try {
            String resp = getApiDirect("api_get_agents.php");
            int i = 0;
            while (true) {
                int nameStart = resp.indexOf("\"name\"", i);
                if (nameStart < 0) break;
                int colon = resp.indexOf(':', nameStart);
                int q1    = resp.indexOf('"', colon + 1);
                int q2    = resp.indexOf('"', q1 + 1);
                if (q1 < 0 || q2 < 0) break;
                names.add(resp.substring(q1 + 1, q2));
                i = q2 + 1;
            }
        } catch (Exception ignored) {}
        return names;
    }

    private static String postApiDirect(String endpoint, String jsonBody) throws Exception {
        // Build URL: strip "lookup.php" from the end and append endpoint
        String base = LEADS_LOOKUP_URL;
        int lastSlash = base.lastIndexOf('/');
        String endpointUrl = (lastSlash >= 0 ? base.substring(0, lastSlash + 1) : base + "/") + endpoint;

        java.net.URL url = new java.net.URL(endpointUrl);
        java.net.HttpURLConnection conn = (java.net.HttpURLConnection) url.openConnection();
        conn.setRequestMethod("POST");
        conn.setConnectTimeout(15_000);   // 15 s connect
        conn.setReadTimeout(30_000);      // 30 s read
        conn.setDoOutput(true);
        conn.setRequestProperty("Content-Type", "application/json; charset=UTF-8");
        conn.setRequestProperty("X-API-Key", API_KEY_VALUE);

        byte[] payload = jsonBody.getBytes(java.nio.charset.StandardCharsets.UTF_8);
        conn.setRequestProperty("Content-Length", String.valueOf(payload.length));
        try (java.io.OutputStream os = conn.getOutputStream()) {
            os.write(payload);
        }

        int code = conn.getResponseCode();
        java.io.InputStream is = (code >= 200 && code < 300)
                ? conn.getInputStream() : conn.getErrorStream();
        if (is == null) return "(HTTP " + code + " — no body)";
        try (BufferedReader br = new BufferedReader(
                new InputStreamReader(is, java.nio.charset.StandardCharsets.UTF_8))) {
            StringBuilder sb = new StringBuilder();
            String line;
            while ((line = br.readLine()) != null) sb.append(line);
            return sb.toString();
        } finally {
            conn.disconnect();
        }
    }

    // -------------------------------------------------------------------------
    // Displays script output (or any message) in a compact scrollable popup.
    // isError=true → red title, ERROR icon; false → INFO icon.
    // -------------------------------------------------------------------------
    private void showScriptOutputPopup(String title, String message, boolean isError) {
        if (message == null) message = "(no output)";
        javax.swing.JTextArea ta = new javax.swing.JTextArea(message.trim());
        ta.setEditable(false);
        ta.setFont(new java.awt.Font("Monospaced", java.awt.Font.PLAIN, 12));
        ta.setLineWrap(true);
        ta.setWrapStyleWord(true);
        ta.setBackground(new java.awt.Color(245, 245, 245));
        ta.setBorder(javax.swing.BorderFactory.createEmptyBorder(6, 8, 6, 8));
        javax.swing.JScrollPane sp = new javax.swing.JScrollPane(ta);
        sp.setPreferredSize(new java.awt.Dimension(460, 160));
        sp.setBorder(null);
        int msgType = isError ? JOptionPane.ERROR_MESSAGE : JOptionPane.INFORMATION_MESSAGE;
        JOptionPane.showMessageDialog(this, sp, title, msgType);
    }

    // -------------------------------------------------------------------------
    // Help dialog — User Guide
    // -------------------------------------------------------------------------
    private void showHelpDialog() {
        String[] sections = {
            "CUSTOMER REQUEST (top field)",
            "  Type or select the Dropbox folder name for the customer.\n"
          + "  Used as the key for all operations below.",

            "NEW REQUEST button",
            "  Opens the New Request dialog.\n"
          + "  Creates a Dropbox folder + DB record.\n"
          + "  After creation, the folder name is copied into Customer Request.",

            "CONFIRM SAFARI button",
            "  Renames the folder in the year directory (2026) to include\n"
          + "  travel dates and status, then moves it into 001_Safari.\n"
          + "  Required fields: Start Date (DDMMM), End Date (DDMMMYYYY),\n"
          + "  Middle Date (DDMMM or NA). When connected to the hub,\n"
          + "  the DB status is set to Booked and the booking date is saved.",

            "COPY PROGRAMS button",
            "  Copies the selected program templates (Duma, Pumba, etc.)\n"
          + "  into the customer's Dropbox folder.\n"
          + "  Customer Request and Prog Number must be filled.",

            "RENAME (Dropbox Folder section)",
            "  Renames a folder inside the selected year/sub-folder.\n"
          + "  Current name = name to rename FROM.\n"
          + "  New name = name to rename TO.\n"
          + "  Status tags (PROGRESS, DEPOSIT, etc.) are managed automatically.\n"
          + "  'Copy To Customer Request' puts the new name into Customer Request.",

            "SEARCH 2026 / 001_Safari buttons",
            "  Searches for matching folder names in the respective directory.\n"
          + "  Double-click a result to populate Customer Request.",

            "LOOKUP LEADS (menu)",
            "  Opens the hub Leads lookup page in your browser,\n"
          + "  pre-filled with the current Customer Request text.",

            "DATE FORMATS",
            "  Start/Middle Date : DDMMM        e.g.  05JAN\n"
          + "  End Date          : DDMMMYYYY    e.g.  18JAN2026",

            "CONFIGURATION",
            "  File: config.properties (same folder as the app JAR)\n"
          + "  use.api=true/false   — enable hub API integration\n"
          + "  api.base_url=...     — hub base URL\n"
          + "  api.key=...          — shared API key"
        };

        javax.swing.JPanel panel = new javax.swing.JPanel();
        panel.setLayout(new javax.swing.BoxLayout(panel, javax.swing.BoxLayout.Y_AXIS));
        panel.setBorder(javax.swing.BorderFactory.createEmptyBorder(8, 4, 8, 4));

        for (int i = 0; i < sections.length; i += 2) {
            // Section header
            javax.swing.JLabel header = new javax.swing.JLabel(sections[i]);
            header.setFont(new java.awt.Font("SansSerif", java.awt.Font.BOLD, 12));
            header.setForeground(new java.awt.Color(160, 26, 20));
            header.setBorder(javax.swing.BorderFactory.createEmptyBorder(
                i == 0 ? 0 : 10, 0, 2, 0));
            header.setAlignmentX(java.awt.Component.LEFT_ALIGNMENT);
            panel.add(header);
            // Section body
            javax.swing.JTextArea body = new javax.swing.JTextArea(sections[i + 1]);
            body.setEditable(false);
            body.setFont(new java.awt.Font("SansSerif", java.awt.Font.PLAIN, 12));
            body.setBackground(panel.getBackground());
            body.setLineWrap(true);
            body.setWrapStyleWord(true);
            body.setAlignmentX(java.awt.Component.LEFT_ALIGNMENT);
            body.setMaximumSize(new java.awt.Dimension(540, 200));
            panel.add(body);
        }

        javax.swing.JScrollPane sp = new javax.swing.JScrollPane(panel);
        sp.setPreferredSize(new java.awt.Dimension(580, 480));
        sp.getVerticalScrollBar().setUnitIncrement(12);
        sp.setBorder(null);

        javax.swing.JDialog dlg = new javax.swing.JDialog(this, "Help — User Guide", true);
        dlg.setLayout(new java.awt.BorderLayout());
        dlg.add(sp, java.awt.BorderLayout.CENTER);
        javax.swing.JButton close = new javax.swing.JButton("Close");
        close.addActionListener(e -> dlg.dispose());
        javax.swing.JPanel bottom = new javax.swing.JPanel(
            new java.awt.FlowLayout(java.awt.FlowLayout.RIGHT));
        bottom.add(close);
        dlg.add(bottom, java.awt.BorderLayout.SOUTH);
        dlg.setSize(620, 560);
        dlg.setLocationRelativeTo(this);
        dlg.setVisible(true);
    }

    // -------------------------------------------------------------------------
    // Opens the Leads lookup page in the system browser, pre-filled with the
    // current Customer Request string from jTextField1.
    // -------------------------------------------------------------------------
    private void lookupLeadsInBrowser() {
        String query = jTextField1.getText().trim();
        try {
            String url;
            if (query.isEmpty()) {
                // No customer request typed — open the bare lookup page
                url = LEADS_LOOKUP_URL;
            } else {
                String encoded = URLEncoder.encode(query, "UTF-8");
                url = LEADS_LOOKUP_URL + "?q=" + encoded;
            }
            Desktop.getDesktop().browse(new URI(url));
        } catch (Exception ex) {
            javax.swing.JOptionPane.showMessageDialog(this,
                "Could not open browser:\n" + ex.getMessage(),
                "Lookup Leads", javax.swing.JOptionPane.ERROR_MESSAGE);
        }
    }

    // Load config.properties — returns true if API is enabled and configured.
    // -------------------------------------------------------------------------
    private boolean loadConfig() {
        Properties props = new Properties();
        try (FileInputStream fis = new FileInputStream(CONFIG_FILE)) {
            props.load(fis);
        } catch (java.io.IOException e) {
            System.out.println("config.properties not found — running without API (" + e.getMessage() + ")");
            return false;
        }
        boolean useApi = "true".equalsIgnoreCase(props.getProperty("use.api", "false"));
        if (!useApi) return false;

        String baseUrl = props.getProperty("api.base_url", "").trim();
        String apiKey  = props.getProperty("api.key",      "").trim();
        if (baseUrl.isEmpty()) {
            System.out.println("config.properties: api.base_url not set — API disabled");
            return false;
        }
        AppSession.api = new ApiClient(baseUrl, apiKey);
        API_KEY_VALUE = apiKey;
        // Derive the Leads lookup URL using only scheme+host from baseUrl,
        // so it works regardless of whether baseUrl already includes a sub-path.
        try {
            java.net.URL parsed = new java.net.URL(baseUrl);
            LEADS_LOOKUP_URL = parsed.getProtocol() + "://" + parsed.getHost()
                               + "/modules/leads/lookup.php";
        } catch (Exception ignored) {
            // keep the hardcoded default
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Called by NewRequestDialog on success to populate the Customer File field.
    // -------------------------------------------------------------------------
    public void setCustomerFile(String folderName) {
        jTextField1.setText(folderName);
    }

    // ── Destination selector for Confirm Safari ───────────────────────────────
    /**
     * Shows a dialog asking the trip destination.
     * Returns the suffix to insert inside () in the folder name,
     * or null if the user cancelled.
     * Tanzania/Beach returns "" — no suffix added.
     */
    private String askDestination() {
        String[][] options = {
            { "Safari / Safari & Beach — Tanzania",  ""             },
            { "Trekking Kilimanjaro / Meru",         "-TREK"        },
            { "Only Zanzibar",                       "-ZNZ"         },
            { "Safari Kenya-Tanzania",               "-TZ-KENYA"    },
            { "Safari Kenya",                        "-KENYA"       },
            { "Uganda",                              "-UGANDA"      },
            { "Namibia",                             "-NAMIBIA"     },
            { "South Africa",                        "-SOUTHAFRICA" },
            { "Rwanda",                              "-RWANDA"      },
            { "Madagascar",                          "-MADAGASCAR"  },
            { "Botswana",                            "-BOTSWANA"    },
        };
        String[] labels = new String[options.length];
        for (int i = 0; i < options.length; i++) labels[i] = options[i][0];

        javax.swing.JComboBox<String> combo = new javax.swing.JComboBox<>(labels);
        combo.setFont(new java.awt.Font("SansSerif", java.awt.Font.PLAIN, 13));
        combo.setPreferredSize(new java.awt.Dimension(320, 28));

        javax.swing.JPanel panel = new javax.swing.JPanel(new java.awt.BorderLayout(0, 8));
        javax.swing.JLabel lbl = new javax.swing.JLabel("Where is the trip taking place?");
        lbl.setFont(new java.awt.Font("SansSerif", java.awt.Font.BOLD, 13));
        panel.add(lbl, java.awt.BorderLayout.NORTH);
        panel.add(combo, java.awt.BorderLayout.CENTER);

        int res = JOptionPane.showConfirmDialog(
            this, panel, "Trip Destination",
            JOptionPane.OK_CANCEL_OPTION, JOptionPane.QUESTION_MESSAGE);

        if (res != JOptionPane.OK_OPTION) return null;
        return options[combo.getSelectedIndex()][1];
    }

    // ── Safari Booking Email Dialog ───────────────────────────────────────────
    /**
     * Shown after a successful "Confirm Safari". Pre-fills a booking notification
     * email and lets the user edit all fields before sending via SMTP.
     */
    private void showSafariBookingEmailDialog(String folderName) {
        // Get the agent email from the DB via API.
        // If the API returns nothing, the CC field is left empty — no auto-generation.
        String agentEmail = "";
        if (USE_API && AppSession.isLoggedIn()) {
            try {
                String body = "{\"folder_name\":\"" + escJsonStatic(folderName) + "\"}";
                String resp = postApiDirect("api_get_agent_email.php", body);
                if (resp != null && resp.contains("\"success\":true")) {
                    String extracted = ApiClient.jsonGetString(resp, "email");
                    if (extracted != null && !extracted.isBlank()) agentEmail = extracted;
                }
            } catch (Exception ignored) {}
        }
        buildAndShowEmailDialog(folderName, agentEmail);
    }

    /**
     * Returns the real email for a given agent name (as extracted from folder).
     * Most agents follow firstname@savannahexplorers.com; exceptions are listed below.
     * Update this map whenever an agent's email changes.
     */
    private String deriveAgentEmail(String agentName) {
        if (agentName == null || agentName.isEmpty()) return "";
        // Exceptions: agents whose email does NOT follow lowercase(name)@savannahexplorers.com
        java.util.Map<String, String> emailMap = new java.util.HashMap<>();
        emailMap.put("robertocapri", "roberto.capri@savannahexplorers.com");
        emailMap.put("daniela",      "safari@savannahexplorers.com");
        emailMap.put("anderson",     "anderson.jr@savannahexplorers.com");
        String key = agentName.toLowerCase().replace(" ", "");
        return emailMap.getOrDefault(key, agentName.toLowerCase() + "@savannahexplorers.com");
    }

    private void buildAndShowEmailDialog(String folderName, String agentEmail) {
        String agentName = extractAgentFromFolder(folderName);

        // ── Build To ─────────────────────────────────────────────────────────
        java.util.List<String> toList = new java.util.ArrayList<>(java.util.Arrays.asList(
            "accountant@savannahexplorers.com",
            "glady@savannahexplorers.com",
            "operations@savannahexplorers.com"
        ));
        // Add Nuru to To when agent is Roberto, RobertoCapri, Alessia, Daniela
        boolean addNuruToTo = agentName.equalsIgnoreCase("Roberto")
                           || agentName.equalsIgnoreCase("RobertoCapri")
                           || agentName.equalsIgnoreCase("Alessia")
                           || agentName.equalsIgnoreCase("Daniela");
        if (addNuruToTo) toList.add("nuru@savannahexplorers.com");

        // ── Build CC ─────────────────────────────────────────────────────────
        java.util.List<String> ccList = new java.util.ArrayList<>();
        if (!agentEmail.isEmpty()) ccList.add(agentEmail);
        ccList.add("savannah.explorers@gmail.com");
        ccList.add("saruni@savannahexplorers.com");

        boolean nuruInCc = ccList.stream()
            .anyMatch(e -> e.equalsIgnoreCase("nuru@savannahexplorers.com"));
        // Nuru line in body whenever Nuru appears in To OR CC
        boolean addNuruLine = addNuruToTo || nuruInCc;

        // ── Subject ──────────────────────────────────────────────────────────
        // Extract customer name: strip leading {prog}_{date}_ and trailing _START...
        // e.g. "06_09JUN_EleonoraDrago(Oniva-Roberto)_START09JUN_END14JUN2026_CK"
        //   →  "EleonoraDrago(Oniva-Roberto)"
        String customerPart = folderName
            .replaceFirst("^\\d+_\\d+[A-Za-z]+_", "")   // strip leading 06_09JUN_
            .replaceFirst("_START.+$", "");               // strip trailing _START...
        String subject = customerPart + " safari bookings";

        // ── Body ─────────────────────────────────────────────────────────────
        String agentDisplay = agentName.equalsIgnoreCase("RobertoCapri") ? "Roberto Capri" : agentName;
        StringBuilder body = new StringBuilder();
        body.append("Hi Glady/Lydia,\n");
        body.append("         you can book for this safari as in the excel file\n\n");
        body.append("Dropbox folder is   ").append(folderName).append("\n\n");
        body.append("Kindly check domestic flights, invoices, transfers and activities are correctly");
        body.append(" booked and invoiced for the correct price/date/pax before saving.");
        body.append(" Put invoice details and your name in Excel after it's checked.\n\n");
        if (addNuruLine) {
            body.append("Nuru - prepare the final program when bookings are completed\n\n");
        }
        body.append("Esther - prepare the not paid invoice\n");
        body.append("\nThanks\nBest Regards,\n").append(agentDisplay);

        // ── Build Dialog ──────────────────────────────────────────────────────
        javax.swing.JDialog dlg = new javax.swing.JDialog(this, "Send Booking Email", true);
        dlg.setLayout(new java.awt.BorderLayout(0, 0));
        dlg.setSize(740, 680);
        dlg.setMinimumSize(new java.awt.Dimension(600, 540));
        dlg.setLocationRelativeTo(this);

        // Header
        javax.swing.JPanel hdrPanel = new javax.swing.JPanel(new java.awt.FlowLayout(java.awt.FlowLayout.LEFT, 14, 10));
        hdrPanel.setBackground(new java.awt.Color(0x1A3A1A));
        javax.swing.JLabel hdrLbl = new javax.swing.JLabel("\u2709  Booking Notification Email");
        hdrLbl.setFont(new java.awt.Font("SansSerif", java.awt.Font.BOLD, 14));
        hdrLbl.setForeground(java.awt.Color.WHITE);
        hdrPanel.add(hdrLbl);
        dlg.add(hdrPanel, java.awt.BorderLayout.NORTH);

        // Form panel
        javax.swing.JPanel form = new javax.swing.JPanel(new java.awt.GridBagLayout());
        form.setBorder(javax.swing.BorderFactory.createEmptyBorder(14, 16, 8, 16));
        form.setBackground(java.awt.Color.WHITE);
        java.awt.GridBagConstraints gc = new java.awt.GridBagConstraints();
        gc.fill = java.awt.GridBagConstraints.HORIZONTAL;
        gc.insets = new java.awt.Insets(4, 4, 4, 4);
        gc.anchor = java.awt.GridBagConstraints.WEST;

        java.awt.Font labelFont = new java.awt.Font("SansSerif", java.awt.Font.BOLD, 12);
        java.awt.Font fieldFont = new java.awt.Font("SansSerif", java.awt.Font.PLAIN, 12);

        // To
        gc.gridx = 0; gc.gridy = 0; gc.weightx = 0; gc.gridwidth = 1;
        javax.swing.JLabel toLabel = new javax.swing.JLabel("To:");
        toLabel.setFont(labelFont);
        form.add(toLabel, gc);
        javax.swing.JTextField toField = new javax.swing.JTextField(String.join(", ", toList));
        toField.setFont(fieldFont);
        gc.gridx = 1; gc.weightx = 1;
        form.add(toField, gc);

        // CC
        gc.gridx = 0; gc.gridy = 1; gc.weightx = 0;
        javax.swing.JLabel ccLabel = new javax.swing.JLabel("CC:");
        ccLabel.setFont(labelFont);
        form.add(ccLabel, gc);
        javax.swing.JTextField ccField = new javax.swing.JTextField(String.join(", ", ccList));
        ccField.setFont(fieldFont);
        gc.gridx = 1; gc.weightx = 1;
        form.add(ccField, gc);

        // Subject
        gc.gridx = 0; gc.gridy = 2; gc.weightx = 0;
        javax.swing.JLabel subjLabel = new javax.swing.JLabel("Subject:");
        subjLabel.setFont(labelFont);
        form.add(subjLabel, gc);
        javax.swing.JTextField subjField = new javax.swing.JTextField(subject);
        subjField.setFont(fieldFont);
        gc.gridx = 1; gc.weightx = 1;
        form.add(subjField, gc);

        // Body
        gc.gridx = 0; gc.gridy = 3; gc.weightx = 0; gc.anchor = java.awt.GridBagConstraints.NORTHWEST;
        javax.swing.JLabel bodyLabel = new javax.swing.JLabel("Body:");
        bodyLabel.setFont(labelFont);
        form.add(bodyLabel, gc);
        javax.swing.JTextArea bodyArea = new javax.swing.JTextArea(body.toString(), 14, 50);
        bodyArea.setFont(fieldFont);
        bodyArea.setLineWrap(true);
        bodyArea.setWrapStyleWord(true);
        javax.swing.JScrollPane bodyScroll = new javax.swing.JScrollPane(bodyArea);
        bodyScroll.setPreferredSize(new java.awt.Dimension(580, 260));
        gc.gridx = 1; gc.weightx = 1; gc.weighty = 1;
        gc.fill = java.awt.GridBagConstraints.BOTH;
        form.add(bodyScroll, gc);

        // SMTP info label removed — email is sent via PHP API
        dlg.add(form, java.awt.BorderLayout.CENTER);

        // Footer buttons
        javax.swing.JPanel foot = new javax.swing.JPanel(new java.awt.FlowLayout(java.awt.FlowLayout.RIGHT, 10, 8));
        foot.setBackground(new java.awt.Color(0xF5F5F5));
        foot.setBorder(javax.swing.BorderFactory.createMatteBorder(1, 0, 0, 0, new java.awt.Color(0xDDDDDD)));

        javax.swing.JButton cancelMailBtn = new javax.swing.JButton("Cancel");
        cancelMailBtn.setFont(new java.awt.Font("SansSerif", java.awt.Font.PLAIN, 12));
        cancelMailBtn.addActionListener(ev -> dlg.dispose());

        javax.swing.JButton sendBtn = new javax.swing.JButton("Send Email");
        sendBtn.setFont(new java.awt.Font("SansSerif", java.awt.Font.BOLD, 12));
        sendBtn.setBackground(new java.awt.Color(0x1A6B3A));
        sendBtn.setForeground(java.awt.Color.WHITE);
        sendBtn.setOpaque(true);
        sendBtn.setBorderPainted(false);

        sendBtn.addActionListener(ev -> {
            sendBtn.setEnabled(false);
            sendBtn.setText("Sending\u2026");
            final String toVal   = toField.getText().trim();
            final String ccVal   = ccField.getText().trim();
            final String subjVal = subjField.getText().trim();
            final String bodyVal = bodyArea.getText();
            new Thread(() -> {
                try {
                    // Build JSON and call PHP endpoint
                    StringBuilder jb = new StringBuilder("{");
                    jb.append("\"to\":\"").append(escJsonStatic(toVal)).append("\",");
                    jb.append("\"cc\":\"").append(escJsonStatic(ccVal)).append("\",");
                    jb.append("\"subject\":\"").append(escJsonStatic(subjVal)).append("\",");
                    jb.append("\"agent_name\":\"").append(escJsonStatic(agentName)).append("\",");
                    jb.append("\"folder_name\":\"").append(escJsonStatic(folderName)).append("\",");
                    jb.append("\"user_id\":").append(AppSession.userId).append(",");
                    jb.append("\"body\":\"").append(escJsonStatic(bodyVal)).append("\"");
                    jb.append("}");
                    String resp = postApiDirect("api_send_email.php", jb.toString());
                    boolean ok = resp != null && resp.contains("\"success\":true");
                    javax.swing.SwingUtilities.invokeLater(() -> {
                        if (ok) {
                            JOptionPane.showMessageDialog(dlg,
                                "Email sent successfully.", "Sent", JOptionPane.INFORMATION_MESSAGE);
                            dlg.dispose();
                        } else {
                            sendBtn.setEnabled(true);
                            sendBtn.setText("Send Email");
                            String errMsg = ApiClient.jsonGetString(resp != null ? resp : "", "message");
                            JOptionPane.showMessageDialog(dlg,
                                "Failed to send email:\n" + (errMsg != null ? errMsg : resp),
                                "Error", JOptionPane.ERROR_MESSAGE);
                        }
                    });
                } catch (Exception ex) {
                    javax.swing.SwingUtilities.invokeLater(() -> {
                        sendBtn.setEnabled(true);
                        sendBtn.setText("Send Email");
                        JOptionPane.showMessageDialog(dlg,
                            "Error calling API:\n" + ex.getMessage(),
                            "Error", JOptionPane.ERROR_MESSAGE);
                    });
                }
            }, "email-send").start();
        });

        foot.add(cancelMailBtn);
        foot.add(sendBtn);
        dlg.add(foot, java.awt.BorderLayout.SOUTH);
        dlg.getRootPane().setDefaultButton(sendBtn);
        dlg.setVisible(true);
    }

    /** Static JSON escape helper (used inside lambdas where instance escJson is out of scope). */
    private static String escJsonStatic(String s) {
        if (s == null) return "";
        return s.replace("\\", "\\\\").replace("\"", "\\\"")
                .replace("\n", "\\n").replace("\r", "\\r").replace("\t", "\\t");
    }

    /** Destination suffixes that can appear INSIDE the () of a confirmed folder name.
     *  Must be listed longest-first so "-TZ-KENYA" matches before "-KENYA". */
    private static final String[] DEST_SUFFIXES = {
        "-TZ-KENYA", "-SOUTHAFRICA", "-MADAGASCAR", "-BOTSWANA",
        "-NAMIBIA", "-UGANDA", "-RWANDA", "-KENYA", "-TREK", "-ZNZ"
    };

    /** Extract agent first name from a folder name like:
     *  01_10JAN_CustomerName(Agency-AgentName-TREK)_START...
     *  Strips destination suffixes before applying agent-extraction logic. */
    private String extractAgentFromFolder(String folder) {
        if (folder == null) return "";
        int p1 = folder.indexOf('(');
        int p2 = folder.indexOf(')');
        if (p1 < 0 || p2 < 0 || p2 <= p1) return "";
        String inner = folder.substring(p1 + 1, p2);
        // Strip any destination suffix added during confirm (e.g. "-TREK", "-ZNZ")
        String upperInner = inner.toUpperCase();
        for (String suffix : DEST_SUFFIXES) {
            if (upperInner.endsWith(suffix)) {
                inner = inner.substring(0, inner.length() - suffix.length());
                break;
            }
        }
        // inner is now: "Agency-AgentName" | "AgentName-Drct" | "AgentName-SB" | "AgentName"
        int dash = inner.lastIndexOf('-');
        if (dash >= 0) {
            String afterDash = inner.substring(dash + 1);
            if (afterDash.equalsIgnoreCase("Drct") || afterDash.equalsIgnoreCase("SB")) {
                return inner.substring(0, dash); // agent is the part BEFORE the channel suffix
            }
            return afterDash; // agent is the part AFTER the agency
        }
        return inner;
    }

    // ── Folder Search Dialog ──────────────────────────────────────────────────
    /**
     * Shows a search popup for a given base folder. User can type to filter
     * sub-folders and files. Double-click opens in Explorer / default app.
     */
    private void showFolderSearchDialog(String title, String basePath) {
        java.io.File baseDir = new java.io.File(basePath);

        javax.swing.JDialog dlg = new javax.swing.JDialog(this, "Search — " + title, false);
        dlg.setLayout(new java.awt.BorderLayout(0, 0));
        dlg.setSize(700, 520);
        dlg.setMinimumSize(new java.awt.Dimension(500, 380));
        dlg.setLocationRelativeTo(this);

        // Header
        javax.swing.JPanel hdrPanel = new javax.swing.JPanel(new java.awt.FlowLayout(java.awt.FlowLayout.LEFT, 14, 10));
        hdrPanel.setBackground(new java.awt.Color(0x00274D));
        javax.swing.JLabel hdrLbl = new javax.swing.JLabel("📁  " + title);
        hdrLbl.setFont(new java.awt.Font("SansSerif", java.awt.Font.BOLD, 14));
        hdrLbl.setForeground(java.awt.Color.WHITE);
        hdrPanel.add(hdrLbl);
        dlg.add(hdrPanel, java.awt.BorderLayout.NORTH);

        // Search field
        javax.swing.JPanel searchPanel = new javax.swing.JPanel(new java.awt.BorderLayout(6, 0));
        searchPanel.setBorder(javax.swing.BorderFactory.createEmptyBorder(10, 12, 6, 12));
        searchPanel.setBackground(java.awt.Color.WHITE);
        javax.swing.JLabel searchLbl = new javax.swing.JLabel("🔍");
        searchLbl.setFont(new java.awt.Font("SansSerif", java.awt.Font.PLAIN, 14));
        javax.swing.JTextField searchField = new javax.swing.JTextField();
        searchField.setFont(new java.awt.Font("SansSerif", java.awt.Font.PLAIN, 13));
        searchField.setBorder(javax.swing.BorderFactory.createCompoundBorder(
            javax.swing.BorderFactory.createLineBorder(new java.awt.Color(0xBBBBBB)),
            javax.swing.BorderFactory.createEmptyBorder(4, 6, 4, 6)));
        javax.swing.JLabel statusLbl = new javax.swing.JLabel("Loading...");
        statusLbl.setFont(new java.awt.Font("SansSerif", java.awt.Font.ITALIC, 11));
        statusLbl.setForeground(new java.awt.Color(0x888888));
        searchPanel.add(searchLbl, java.awt.BorderLayout.WEST);
        searchPanel.add(searchField, java.awt.BorderLayout.CENTER);
        searchPanel.add(statusLbl, java.awt.BorderLayout.EAST);
        dlg.add(searchPanel, java.awt.BorderLayout.NORTH);

        // Results list — stores File objects, renders relative path + type icon
        javax.swing.DefaultListModel<java.io.File> listModel = new javax.swing.DefaultListModel<>();
        javax.swing.JList<java.io.File> resultList = new javax.swing.JList<>(listModel);
        resultList.setFont(new java.awt.Font("SansSerif", java.awt.Font.PLAIN, 12));
        resultList.setSelectionMode(javax.swing.ListSelectionModel.SINGLE_SELECTION);
        resultList.setCellRenderer(new javax.swing.DefaultListCellRenderer() {
            @Override
            public java.awt.Component getListCellRendererComponent(
                    javax.swing.JList<?> list, Object value, int index,
                    boolean isSelected, boolean cellHasFocus) {
                super.getListCellRendererComponent(list, value, index, isSelected, cellHasFocus);
                if (value instanceof java.io.File) {
                    java.io.File f = (java.io.File) value;
                    String rel = f.getAbsolutePath();
                    if (rel.startsWith(basePath)) rel = rel.substring(basePath.length());
                    if (rel.startsWith("\\") || rel.startsWith("/")) rel = rel.substring(1);
                    setText("\uD83D\uDCC1 " + rel);
                }
                return this;
            }
        });
        javax.swing.JScrollPane listScroll = new javax.swing.JScrollPane(resultList);
        listScroll.setBorder(javax.swing.BorderFactory.createEmptyBorder(0, 12, 0, 12));
        dlg.add(listScroll, java.awt.BorderLayout.CENTER);

        // Footer
        javax.swing.JPanel foot = new javax.swing.JPanel(new java.awt.FlowLayout(java.awt.FlowLayout.RIGHT, 10, 8));
        foot.setBackground(new java.awt.Color(0xF5F5F5));
        foot.setBorder(javax.swing.BorderFactory.createMatteBorder(1, 0, 0, 0, new java.awt.Color(0xDDDDDD)));
        javax.swing.JButton openRootBtn = new javax.swing.JButton("Open Root Folder");
        openRootBtn.setFont(new java.awt.Font("SansSerif", java.awt.Font.PLAIN, 12));
        javax.swing.JButton openSelBtn  = new javax.swing.JButton("Open Selected");
        openSelBtn.setFont(new java.awt.Font("SansSerif", java.awt.Font.BOLD, 12));
        javax.swing.JButton closeBtn    = new javax.swing.JButton("Close");
        closeBtn.setFont(new java.awt.Font("SansSerif", java.awt.Font.PLAIN, 12));
        foot.add(openRootBtn); foot.add(openSelBtn); foot.add(closeBtn);
        dlg.add(foot, java.awt.BorderLayout.SOUTH);

        // ── Scan folder tree in background ────────────────────────────────────
        final java.util.List<java.io.File> allFiles = new java.util.ArrayList<>();
        new Thread(() -> {
            scanFolderTree(baseDir, allFiles);
            javax.swing.SwingUtilities.invokeLater(() -> {
                listModel.clear();
                for (java.io.File f : allFiles) listModel.addElement(f);
                statusLbl.setText(allFiles.size() + " items");
            });
        }, "folder-scan").start();

        // ── Filter on typing ──────────────────────────────────────────────────
        searchField.getDocument().addDocumentListener(new javax.swing.event.DocumentListener() {
            private void filter() {
                String q = searchField.getText().toLowerCase().trim();
                listModel.clear();
                for (java.io.File f : new java.util.ArrayList<>(allFiles)) {
                    if (q.isEmpty() || f.getName().toLowerCase().contains(q)) {
                        listModel.addElement(f);
                    }
                }
                statusLbl.setText(listModel.size() + " / " + allFiles.size());
            }
            public void insertUpdate(javax.swing.event.DocumentEvent e) { javax.swing.SwingUtilities.invokeLater(this::filter); }
            public void removeUpdate(javax.swing.event.DocumentEvent e)  { javax.swing.SwingUtilities.invokeLater(this::filter); }
            public void changedUpdate(javax.swing.event.DocumentEvent e) {}
        });

        // ── Open on double-click ──────────────────────────────────────────────
        java.awt.event.MouseListener doubleClick = new java.awt.event.MouseAdapter() {
            @Override public void mouseClicked(java.awt.event.MouseEvent e) {
                if (e.getClickCount() == 2) openFileOrFolder(resultList.getSelectedValue());
            }
        };
        resultList.addMouseListener(doubleClick);

        // Button actions
        openSelBtn.addActionListener(e -> openFileOrFolder(resultList.getSelectedValue()));
        openRootBtn.addActionListener(e -> openFileOrFolder(baseDir));
        closeBtn.addActionListener(e -> dlg.dispose());

        // Lay out the north panel correctly (search below header)
        // The BorderLayout.NORTH slot was taken by hdrPanel; we need a compound north.
        dlg.remove(hdrPanel);
        dlg.remove(searchPanel);
        javax.swing.JPanel northPanel = new javax.swing.JPanel(new java.awt.BorderLayout());
        northPanel.add(hdrPanel, java.awt.BorderLayout.NORTH);
        northPanel.add(searchPanel, java.awt.BorderLayout.CENTER);
        dlg.add(northPanel, java.awt.BorderLayout.NORTH);

        dlg.setVisible(true);
        searchField.requestFocusInWindow();
    }

    /** Recursively scan a folder, adding all direct children (both files and folders),
     *  then recursing into sub-folders. Only 3 levels deep to keep it fast. */
    private void scanFolderTree(java.io.File dir, java.util.List<java.io.File> result) {
        scanFolderTree(dir, result, 0, 4);
    }
    private void scanFolderTree(java.io.File dir, java.util.List<java.io.File> result, int depth, int maxDepth) {
        if (depth >= maxDepth) return;
        java.io.File[] children = dir.listFiles(java.io.File::isDirectory);  // folders only
        if (children == null) return;
        java.util.Arrays.sort(children, (a, b) -> a.getName().compareToIgnoreCase(b.getName()));
        for (java.io.File f : children) {
            result.add(f);
            scanFolderTree(f, result, depth + 1, maxDepth);
        }
    }

    /** Open a file with the default app, or a folder in Explorer. */
    private void openFileOrFolder(java.io.File f) {
        if (f == null) return;
        try {
            if (Desktop.isDesktopSupported()) {
                Desktop.getDesktop().open(f);
            } else {
                Runtime.getRuntime().exec(new String[]{"cmd", "/c", "explorer.exe", f.getAbsolutePath()});
            }
        } catch (Exception ex) {
            JOptionPane.showMessageDialog(this, "Cannot open:\n" + f.getAbsolutePath()
                + "\n" + ex.getMessage(), "Error", JOptionPane.ERROR_MESSAGE);
        }
    }

    // ── Status Report popup ───────────────────────────────────────────────────
    private StatusReportDialog statusReportDialog = null;

    private void showStatusReport() {
        String base = System.getenv("DROPBOX_HOME");
        if (base == null || base.isEmpty()) {
            JOptionPane.showMessageDialog(this,
                "DROPBOX_HOME environment variable is not set.",
                "Status Reports", JOptionPane.ERROR_MESSAGE);
            return;
        }
        // Reuse existing dialog if still open, otherwise create a new one
        if (statusReportDialog == null || !statusReportDialog.isDisplayable()) {
            java.util.List<String> agents = fetchActiveAgentNames();
            statusReportDialog = new StatusReportDialog(
                this,
                base + "\\001_Safari",
                folderName -> jTextField1.setText(folderName),
                agents
            );
        }
        statusReportDialog.toFront();
        statusReportDialog.setVisible(true);
    }

    // Variables declaration - do not modify//GEN-BEGIN:variables
    private javax.swing.JCheckBox BeachDumaShort;
    private javax.swing.JCheckBox DumaPemba;
    private javax.swing.JCheckBox GranSafari;
    private javax.swing.JCheckBox Lemosho10days;
    private javax.swing.JCheckBox Marangu7days;
    private javax.swing.JCheckBox PumbaPemba;
    private javax.swing.JCheckBox Simba2;
    private javax.swing.JCheckBox Simba3;
    private javax.swing.JCheckBox SimbaPemba;
    private javax.swing.JCheckBox SundayGRP;
    private javax.swing.JCheckBox ThursdayGRP;
    private javax.swing.JButton jButton1;
    private javax.swing.JButton jButton10;
    private javax.swing.JButton jButton11;
    private javax.swing.JButton jButton12;
    private javax.swing.JButton jButton13;
    private javax.swing.JButton jButton14;    private javax.swing.JButton jButton15;
    private javax.swing.JButton jButton17;
    private javax.swing.JButton jButton18;
    private javax.swing.JButton jButton2;
    private javax.swing.JButton jButton3;
    private javax.swing.JButton jButton4;
    private javax.swing.JButton jButton5;
    private javax.swing.JButton jButton6;
    private javax.swing.JButton jButton7;
    private javax.swing.JButton jButton8;
    private javax.swing.JButton jButton9;
    private javax.swing.JComboBox<String> jComboBoxFrom;
    private javax.swing.JComboBox<String> jComboBoxTo;
    private javax.swing.JButton jButtonRename;
    private javax.swing.JButton jButtonClearRename;
    private javax.swing.JButton jButtonToCustomerFile;
    private javax.swing.JCheckBox jCheckBox1;
    private javax.swing.JCheckBox jCheckBox11;
    private javax.swing.JCheckBox jCheckBox12;
    private javax.swing.JCheckBox jCheckBox13;
    private javax.swing.JCheckBox jCheckBox16;
    private javax.swing.JCheckBox jCheckBox17;
    private javax.swing.JCheckBox jCheckBox19;
    private javax.swing.JCheckBox jCheckBox2;
    private javax.swing.JCheckBox jCheckBox22;
    private javax.swing.JCheckBox jCheckBox24;
    private javax.swing.JCheckBox jCheckBox25;
    private javax.swing.JCheckBox jCheckBox26;
    private javax.swing.JCheckBox jCheckBox27;
    private javax.swing.JCheckBox jCheckBox28;
    private javax.swing.JCheckBox jCheckBox3;
    private javax.swing.JCheckBox jCheckBox4;
    private javax.swing.JCheckBox jCheckBox5;
    private javax.swing.JCheckBox jCheckBox6;
    private javax.swing.JCheckBox jCheckBox7;
    private javax.swing.JCheckBox jCheckBox8;
    private javax.swing.JCheckBox jCheckBox9;
    private javax.swing.JCheckBox jDC;
    private javax.swing.JCheckBox jDumaShort;
    private javax.swing.JCheckBox jKC;
    private javax.swing.JLabel jLabel1;
    private javax.swing.JLabel jLabel10;
    private javax.swing.JLabel jLabel11;
    private javax.swing.JLabel jLabel12;
    private javax.swing.JLabel jLabel14;
    private javax.swing.JLabel jLabel2;
    private javax.swing.JLabel jLabel3;
    private javax.swing.JLabel jLabel4;
    private javax.swing.JLabel jLabel5;
    private javax.swing.JLabel jLabel6;
    private javax.swing.JLabel jLabel7;
    private javax.swing.JLabel jLabel8;
    private javax.swing.JLabel jLabel9;
    private javax.swing.JCheckBox jLuxDuma;
    private javax.swing.JCheckBox jLuxPumba;
    private javax.swing.JCheckBox jLuxSimba;
    private javax.swing.JMenu jMenu1;
    private javax.swing.JMenu jMenu3;
    private javax.swing.JMenu jMenu4;
    private javax.swing.JMenu jMenu5;
    private javax.swing.JMenuBar jMenuBar1;
    private javax.swing.JMenuItem jMenuItem1;
    private javax.swing.JMenuItem jMenuItem13;
    private javax.swing.JMenuItem jMenuItem16;
    private javax.swing.JMenuItem jMenuItem17;
    private javax.swing.JMenuItem jMenuItem2;
    private javax.swing.JMenuItem jMenuItem3;
    private javax.swing.JMenuItem jMenuItem4;
    private javax.swing.JMenuItem jMenuItem6;
    private javax.swing.JCheckBox jPC;
    private javax.swing.JPanel jPanel1;
    private javax.swing.JRadioButtonMenuItem jRadioButtonMenuItem1;
    private javax.swing.JCheckBox jSC;
    private javax.swing.JScrollBar jScrollBar2;
    private javax.swing.JScrollPane jScrollPane1;
    private javax.swing.JScrollPane jScrollPane2;
    private javax.swing.JButton jSearch;
    private javax.swing.JButton jSearchSafari;
    private javax.swing.JTextField jTextField1;
    private javax.swing.JTextField jTextField10;
    private javax.swing.JTextField jTextField10b;
    private javax.swing.JLabel jLabel7b;
    private javax.swing.JTextField jTextField11;
    private javax.swing.JTextField jTextField2;
    private javax.swing.JTextField jTextField3;
    private javax.swing.JTextField jTextField4;
    private javax.swing.JComboBox<String> jTextField5;
    private javax.swing.JTextField jTextField6;
    private javax.swing.JTextField jTextField7;
    private javax.swing.JTextField jTextField8;
    private javax.swing.JTextField jTextField9;
    private javax.swing.JCheckBox machame;
    private javax.swing.JCheckBox machame6;
    private javax.swing.JComboBox<String> grpActionCombo;
    private javax.swing.JTextField grpCodeField;
    private javax.swing.JButton jButtonRefreshProg;
    // End of variables declaration//GEN-END:variables
}
