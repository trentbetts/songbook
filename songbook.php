<?php
     
	include_once("pdf_lib/tcpdf/tcpdf.php");
    include_once("mypdf.php");

class SongBook{
    // Construction
    function __construct($inputFile, $outputfile){
        
        $this->pdf = new MYPDF();
        $this->pdfPageWidth = $this->pdf->getPageWidth();
        $this->pdfPageHeight = $this->pdf->getPageHeight();
        
        // Setup the return json 
        $this->return_json = new stdClass();
        $this->return_json->status = "success";
        $this->return_json->errors = "";
        //
        // Set the File Names
        //
        $this->input_file_name = $inputFile;
        $this->output_file_name = $outputfile . "_debug.pdf";

    }
    
    function __destruct()
    {
        $this->pdf->Output($this->output_file_name, 'F');
    }
    

    private function drawSongChords(){
        // Initalise the positions
        $chordCount = sizeof($this->song_chords);
        $nBottomGap = 20;
        $nLeftGap = 24;
        $nXPos = 5;
        $nYPos = $this->book_margin_y;
        if($this->song_layout == $this->LAYOUT_CHORDS_BOTTOM){
            $nYPos = $this->pdfPageHeight-40;
            $nXPos = ($this->pdfPageWidth/2)-(($chordCount/2)*$nBottomGap);
        }
        // Go through the chords
        for($i=0; $i < $chordCount; $i++){
            
            $chord_image_name = "./image/uke_".$this->song_chords[$i].".png";  
            if(!fileExists($chord_image_name)){
                //$this->return_json->errors .= "Missing Chord Image $chord_image_name~";
                $chord_image_name = "./image/uke_404.png";  
            }
            $this->pdf->Image($chord_image_name,$nXPos,$nYPos,17);
            // Increment the position    
            if($this->song_layout == $this->LAYOUT_CHORDS_LEFT)
                $nYPos +=$nLeftGap;
            else
                $nXPos +=$nBottomGap;
        }
    }
    
    private function outpuTitle(){
        //
        // Output the Song Title
        //
        $this->pdf->SetFont($this->book_font_face, 'b', 13);
        $this->pdf->Text($this->book_margin_x_BOTTOM, 5, $this->song_title . " : " . $this->song_artist );	
        $this->pdf->SetFont($this->book_font_face, '', $this->book_font_size );
        // And the song number
        $this->song_count++;
        $this->pdf->Text($this->pdfPageWidth-15, 5, $this->song_count );
        // Add the song to the array of songs while we are here
        $this->book_songs[sizeof($this->book_songs)] = $this->song_title . " : " . $this->song_artist;
        $this->book_links[sizeof($this->book_links)] = $this->pdf->AddLink();		
    }
    
    private function processDirective($line){
        
        if(false !== stripos($line,"{ns")){
            // End the current song
            $this->endSong();
            // Start the new song
            $this->newSong();
        }
        else if(false !== stripos($line,"{lb}")){
            // Add another line
            $this->song_y = $this->pdf->GetFontSize()+$this->song_y+2;		
        }
        else if(false !== stripos($line,"{cb}")){
            // Column break, so draw a line and reset the margin left and reset y
            $halfPage = $this->pdfPageWidth/2;
            $this->pdf->line($halfPage-1,$this->book_margin_y,$halfPage-1,$this->song_y);
            $this->song_x = $halfPage + 1;
            $this->song_y = $this->book_margin_y;
        }
        // Comments
        else if(false !== stripos($line,"{comment:")){
            // Get the comment
            $pureComment = substr($line,strlen("{comment:"));
            $pureComment = substr($pureComment,0,strlen($pureComment)-2);
            $this->song_y += 3;
            $this->pdf->SetFont($this->song_font_face, 'b', $this->song_font_size);
            $this->pdf->Text($this->song_x,$this->song_y,$pureComment);	
            $this->song_y = $this->pdf->GetFontSize()+$this->song_y+2;		
            $this->pdf->SetFont($this->song_font_face, '', $this->song_font_size);
        }
        else if(false !== stripos($line,"{title:")){
            $pureValue = substr($line,strlen("{title:"));
            $pureValue = substr($pureValue,0,strlen($pureValue)-2);
            $this->song_title = $pureValue;
            if($this->song_title != " "&& $this->song_artist != "")
                $this->outpuTitle();
        }
        else if(false !== stripos($line,"{artist:")){
            $pureValue = substr($line,strlen("{artist:"));
            $pureValue = substr($pureValue,0,strlen($pureValue)-2);
            $this->song_artist = $pureValue;
            if($this->song_title != " "&& $this->song_artist != "")
                $this->outpuTitle();
        }
        else if(false !== stripos($line,"{font-offset:")){
            $pureValue = substr($line,strlen("{font-offset:"));
            $pureValue = substr($pureValue,0,strlen($pureValue)-2);
            $this->song_font_size += intVal($pureValue);
            $this->pdf->SetFont($this->song_font_face, '', $this->song_font_size);
        }
        else if(false !== stripos($line,"{display_mode:")){
            $pureValue = substr($line,strlen("{display_mode:"));
            $pureValue = substr($pureValue,0,strlen($pureValue)-2);
            $this->song_layout = intVal($pureValue);
            if($this->song_layout == $this->LAYOUT_CHORDS_BOTTOM){
                $this->song_x = $this->book_margin_x_BOTTOM;
            }
            else{
                $this->song_x = $this->book_margin_x_LEFT;
            }
        }
        else if(false !== stripos($line,"{start_of_chorus}")){
            // Just store where we are so we know where to draw the line
            $this->song_chorus_marker = $this->song_y;
        }
        else if(false !== stripos($line,"{end_of_chorus}")){
            // We are at the end of the chorus
             $this->pdf->line($this->song_x-3,$this->song_chorus_marker,$this->song_x-3,$this->song_y);
        }
            
                
    }
    private function processSongLine($line){
        
        $inQuote = false;
        $currentChordName = "";
        
        $nX = $this->song_x;
        
        for($i = 0; $i < strlen($line); $i++){
            $current = $line[$i];
            // Determine if we are in a quote or not
            if($inQuote == true){
                $currentChordName .= $current; 
                if($current == ']'){
                    $inQuote = false;
                    //
                    // Change the colour and output the font
                    //
                    $this->pdf->SetTextColor(255, 0, 0);
                    $this->pdf->Text($nX,$this->song_y,$currentChordName);	
                    $nX += $this->pdf->GetStringWidth($currentChordName);
                    $this->pdf->SetTextColor(0, 0, 0);
                    //
                    // Add the chords to the arrays
                    //
                    $currentChordName = substr($currentChordName,1,strlen($currentChordName)-2);
                    if(!in_array($currentChordName,$this->song_chords))
                        $this->song_chords[sizeof($this->song_chords)] = $currentChordName;
                    if(!in_array($currentChordName,$this->book_chords))
                        $this->book_chords[sizeof($this->book_chords)] = $currentChordName;
                    // Reset the current chord
                    $currentChordName = "";
                }
                continue;
            }else{
                // Determine if this is the start of a quote
                if($current == '['){
                    $currentChordName .= $current; 
                    $inQuote = true;
                    continue;
                }
                // Standard Song Character
               $this->pdf->Text($nX,$this->song_y,$current);	
               $nX += $this->pdf->GetStringWidth($current);
            }
            
        }
        // Increment the y insertion point
		$this->song_y = $this->pdf->GetFontSize()+$this->song_y+2;		

            
    }
    
    
    private function processLine($line){
        
        // Check if it is a directive or not
        if($line[0]=='{')
            $this->processDirective($line);
        // Comment
        else if($line[0]=='#')
            return;
        // Standard Line
        else if(strlen($line)>2)
            $this->processSongLine($line);
            
        
    }
    
    public function insertTOC(){
        // Goto the page where the TOC will be displayed
        $this->pdf->setPage(1);
        $cell_width = $this->pdfPageWidth;
        // Go through the songs and add those songs to the TOC
        for($i = 0; $i < sizeof($this->book_songs); $i++){
            $this->pdf->setX($this->book_margin_x_BOTTOM);
            $this->pdf->Cell($cell_width,0,($i+1)." : ". $this->book_songs[$i] ,0,1,'L',false,$this->book_links[$i]);
        }
    }
    
    public function processBook(){
        //
        // Insert a page where the table of contents will be displayed
        //
        $this->pdf->AddPage();
        //
        // Assume that there is going to be a song and configure the new song
        //
        $this->newSong();
        // Simply open the file and process each line
        $fp = fopen($this->input_file_name,"rt");
        while(!feof($fp)){
            $line = fgets($fp);
            $this->processLine($line);
        }
        fclose($fp);
        // End the last song 
        $this->endSong();
        // Insert the TOC
        $this->insertTOC();
        // Return any errors
        return $this->return_json;
    }
    
    //Utility Functions
    public function fileExists($path){
    	 return (@fopen($path,"r")==true);
 	}	

    private function endSong(){
        // Draw the song chords
        $this->drawSongChords();
    }
    
    // Reset all of the variables when creating a new song
    private function newSong(){
        
        // Add a page to the pdf
        $this->pdf->AddPage();
        // Reset the font
        $this->song_font_face = $this->book_font_face;
        $this->song_font_size = $this->book_font_size;
        $this->pdf->SetFont($this->song_font_face, '', $this->song_font_size);
        //
        // Reset the insertion points
        //
        $this->song_x = $this->book_margin_x_LEFT;
        $this->song_y = $this->book_margin_y;
        // Set the layout
        $this->song_layout = $this->LAYOUT_CHORDS_LEFT;
        // Reset the title and artist
        $this->song_title = "";
        $this->song_artist = "";
    }
    
    // The pdf object
    private $pdf;
    private $pdfPageWidth;
    private $pdfPageHeight;
    // Arrays for chords
	private $chord_array = Array();
	private $all_chord_array = Array();
    // The return 
    private $return_json;
    //
    // The file information
    //
    private $input_file_name = "";
    private $output_file_name = "";
    // Chord Image Arrays
    private $song_chords = array();
    private $book_chords = array();
    // Array to use in the TOC
    private $book_songs = array();
    private $book_links = array();
    
    private $book_font_face = 'helvetica';
    private $book_font_size = 12;
    private $song_font_face = 'helvetica';
    private $song_font_size = 12;
    
    private $song_x = 0;
    private $song_y = 0;
    private $song_layout; 
    
    private $song_chorus_marker = 0;
    
    private $book_margin_x_LEFT = 32;
    private $book_margin_x_BOTTOM = 5;
    private $book_margin_y = 18;
    
    private $song_title = "";
    private $song_artist = "";
    
    private $LAYOUT_CHORDS_LEFT = 0;
    private $LAYOUT_CHORDS_BOTTOM = 1;
    
    private $song_count = 0;
    
}

?>