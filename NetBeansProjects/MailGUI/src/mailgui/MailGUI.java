/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
package mailgui;

/**
 *
 * @author RDESIBI
 */

import static java.lang.System.out;
import java.io.IOException;
import java.io.File;
import javax.swing.JFileChooser;
import javax.swing.JFrame;
import java.util.Properties;  
import javax.mail.*;
import javax.mail.internet.*; 

public class MailGUI {

    /**
     * @param args the command line arguments
     */
    public static void main(String[] args) {
        // TODO code application logic here
        final String user="savannah.explorers@gmail.com";//change accordingly  
        final String password="agiinqjklugdqapt";//change accordingly  
    
        String to="info@savannahexplorers.com";//change accordingly  
  
   //Get the session object  
        Properties props = new Properties();  
    
        props.put("mail.smtp.host", "smtp.gmail.com");    
        props.put("mail.smtp.socketFactory.class",    
                    "javax.net.ssl.SSLSocketFactory");    
        props.put("mail.smtp.auth", "true");    
        props.put("mail.smtp.port", "465");     
        props.put("mail.smtp.ssl.enable", "true");
     
   Session session = Session.getDefaultInstance(props, 
    new javax.mail.Authenticator() {  
      protected PasswordAuthentication getPasswordAuthentication() {  
    return new PasswordAuthentication(user,password);  
      }  
    });  
  
   //Compose the message  
    try {  
     MimeMessage message = new MimeMessage(session);  
     message.setFrom(new InternetAddress(user));  
     message.addRecipient(Message.RecipientType.TO,new InternetAddress(to));  
     message.setSubject("java sent mail");  
     message.setText("This is simple program of sending email using JavaMail API");  
       
    //send the message  
     Transport.send(message);  
  
     System.out.println("message sent successfully...");  
   
     } catch (MessagingException e) {e.printStackTrace();}  
    }
    
}
