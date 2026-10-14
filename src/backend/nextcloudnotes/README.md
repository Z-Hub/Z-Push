    Install                                                                                                     19% used                                
                                                                                                                 $0.77 spent                             
     1. Copy the file to Z-Push:                                                                                                                         
                                                                                                                                                         
     <z-push>/backend/nextcloudnotes/nextcloudnotes.php                                                                                                  
                                                                                                                                                         
     2. In backend/combined/config.php, add the backend and send Notes to it (keep IMAP for mail):                                                       
                                                                                                                                                         
     'backends' => array(                                                                                                                                
         'i' => array('name' => 'BackendIMAP'),                                                                                                          
         'n' => array('name' => 'BackendNextcloudNotes'),                                                                                                
     ),                                                                                                                                                  
     'folderbackend' => array(                                                                                                                           
         SYNC_FOLDER_TYPE_NOTE => 'n',                                                                                                                   
         SYNC_FOLDER_TYPE_USER_NOTE => 'n',                                                                                                              
         // IMAP types stay on 'i'                                                                                                                       
     ),                                                                                                                                                  
                                                                                                                                                         
     3. Set NCNOTES_URL (and friends) at the top of the file, or define them earlier in config.php. Same-                                                
        compose Docker can use http://nextcloud. With 2FA, use a Nextcloud app password (NCNOTES_PASSWORD if                                             
        it is not the ActiveSync password).               
