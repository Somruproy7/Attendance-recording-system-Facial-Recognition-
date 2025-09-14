<?php
// ss/student_checkin_modal.php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$student_id = $_SESSION['user_id'];
$session_instance_id = $_POST['session_instance_id'] ?? '';

if (empty($session_instance_id)) {
    echo json_encode(['success' => false, 'message' => 'Session ID required']);
    exit();
}

try {
    // Validate session exists and student is enrolled
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare('
        SELECT si.id, si.status, ts.session_title, c.class_name, c.class_code
        FROM session_instances si
        JOIN timetable_sessions ts ON si.timetable_session_id = ts.id
        JOIN classes c ON ts.class_id = c.id
        JOIN student_enrollments se ON se.class_id = c.id
        WHERE si.id = :session_id 
          AND se.student_id = :student_id 
          AND se.status = "enrolled"
          AND si.status IN ("scheduled", "in_progress")
    ');
    $stmt->execute([
        'session_id' => $session_instance_id,
        'student_id' => $student_id
    ]);
    
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        echo json_encode(['success' => false, 'message' => 'Invalid session or not enrolled']);
        exit();
    }
    
    // Create a specialized Python script that integrates with stable_face_recognition.py
    $script_content = '
import cv2
import os
import numpy as np
import time
import threading
import mysql.connector
import json
from datetime import datetime
import sys

# Configuration
PHOTOS_DIR = "../uploads/profiles"
STUDENT_ID = ' . $student_id . '
SESSION_INSTANCE_ID = ' . $session_instance_id . '
DB_CONFIG = {
    "host": "localhost",
    "database": "fullattend_db", 
    "user": "root",
    "password": ""
}

class StudentCheckInRecognition:
    def __init__(self):
        self.reference_faces = []
        self.reference_names = []
        self.reference_student_ids = []
        self.face_cascade = None
        self.cap = None
        self.current_camera_index = 0
        self.available_cameras = []
        self.camera_lock = threading.Lock()
        self.camera_names = {}
        self.running = True
        self.last_matches = []
        self.attendance_marked = False
        self.match_count = 0
        self.required_matches = 5  # Need 5 consecutive matches
        
    def initialize(self):
        """Initialize the face recognition system"""
        try:
            # Load face cascade classifier
            self.face_cascade = cv2.CascadeClassifier(cv2.data.haarcascades + "haarcascade_frontalface_default.xml")
            
            # Load reference faces
            self.load_reference_faces()
            
            # Find available cameras
            self.find_available_cameras()
            
            # Initialize camera
            self.initialize_camera()
            
            return True
        except Exception as e:
            print(f"Initialization error: {e}")
            return False
    
    def load_reference_faces(self):
        """Load reference photos from uploads/profiles directory"""
        image_extensions = [".jpg", ".jpeg", ".png", ".bmp"]
        
        print("Loading your profile photo...")
        if not os.path.exists(PHOTOS_DIR):
            print(f"Profiles directory not found: {PHOTOS_DIR}")
            return
            
        # Look for this specific students photo
        target_filename = str(STUDENT_ID)
        
        for filename in os.listdir(PHOTOS_DIR):
            name_without_ext = os.path.splitext(filename)[0]
            if name_without_ext == target_filename and any(filename.lower().endswith(ext) for ext in image_extensions):
                image_path = os.path.join(PHOTOS_DIR, filename)
                
                try:
                    # Load image
                    img = cv2.imread(image_path)
                    if img is None:
                        continue
                        
                    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
                    
                    # Detect faces
                    faces = self.face_cascade.detectMultiScale(
                        gray, 
                        scaleFactor=1.1, 
                        minNeighbors=5, 
                        minSize=(30, 30)
                    )
                    
                    if len(faces) > 0:
                        # Take the largest face
                        largest_face = max(faces, key=lambda x: x[2] * x[3])
                        x, y, w, h = largest_face
                        
                        # Extract face region
                        face_roi = gray[y:y+h, x:x+w]
                        face_roi = cv2.resize(face_roi, (100, 100))
                        
                        self.reference_faces.append(face_roi)
                        self.reference_names.append(name_without_ext)
                        self.reference_student_ids.append(STUDENT_ID)
                        print(f"✓ Loaded your profile photo: {filename}")
                        return
                    else:
                        print(f"✗ No face found in your profile photo: {filename}")
                        
                except Exception as e:
                    print(f"Error loading {filename}: {e}")
        
        print("✗ Your profile photo not found. Please ensure your photo is uploaded.")
    
    def find_available_cameras(self):
        """Find available camera indices"""
        self.available_cameras = []
        self.camera_names = {}
        print("Scanning for available cameras...")
        
        for i in range(5):
            try:
                cap_test = cv2.VideoCapture(i)
                if cap_test.isOpened():
                    ret, frame = cap_test.read()
                    if ret and frame is not None:
                        self.available_cameras.append(i)
                        width = int(cap_test.get(cv2.CAP_PROP_FRAME_WIDTH))
                        height = int(cap_test.get(cv2.CAP_PROP_FRAME_HEIGHT))
                        camera_info = f"Camera {i} ({width}x{height})"
                        self.camera_names[i] = camera_info
                        print(f"✓ {camera_info}")
                cap_test.release()
            except:
                continue
        
        if not self.available_cameras:
            print("Warning: No cameras detected!")
            self.available_cameras = [0]  # Try default camera
        else:
            print(f"Total cameras found: {len(self.available_cameras)}")
    
    def initialize_camera(self):
        """Initialize camera"""
        if not self.available_cameras:
            print("No cameras available!")
            return False
            
        camera_id = self.available_cameras[self.current_camera_index]
        
        try:
            if self.cap is not None:
                self.cap.release()
                time.sleep(0.5)
            
            self.cap = cv2.VideoCapture(camera_id)
            
            if self.cap.isOpened():
                self.cap.set(cv2.CAP_PROP_FRAME_WIDTH, 640)
                self.cap.set(cv2.CAP_PROP_FRAME_HEIGHT, 480)
                self.cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
                print(f"✓ Camera {camera_id} initialized")
                return True
            else:
                print(f"✗ Failed to initialize camera {camera_id}")
                return False
                
        except Exception as e:
            print(f"Error initializing camera {camera_id}: {e}")
            return False
    
    def compare_faces(self, face1, face2, threshold=0.4):
        """Compare two face images using template matching"""
        try:
            if face1.shape != face2.shape:
                face2 = cv2.resize(face2, (face1.shape[1], face1.shape[0]))
            
            # Normalize images
            face1_norm = cv2.equalizeHist(face1)
            face2_norm = cv2.equalizeHist(face2)
            
            # Template matching
            result = cv2.matchTemplate(face1_norm, face2_norm, cv2.TM_CCOEFF_NORMED)
            similarity = np.max(result)
            
            return similarity > threshold, similarity
        except Exception as e:
            print(f"Error comparing faces: {e}")
            return False, 0.0
    
    def mark_attendance(self):
        """Mark attendance in database"""
        try:
            # Connect to database
            conn = mysql.connector.connect(**DB_CONFIG)
            cursor = conn.cursor()
            
            # Check if attendance already marked
            check_query = """
                SELECT id FROM attendance 
                WHERE student_id = %s AND session_instance_id = %s
            """
            cursor.execute(check_query, (STUDENT_ID, SESSION_INSTANCE_ID))
            existing_record = cursor.fetchone()
            
            if existing_record:
                cursor.close()
                conn.close()
                return False, "Attendance already marked for this session"
            
            # Get current date and time
            now = datetime.now()
            current_date = now.strftime("%Y-%m-%d")
            current_time_str = now.strftime("%H:%M:%S")
            
            # Insert attendance record
            insert_query = """
                INSERT INTO attendance (student_id, session_instance_id, attendance_date, attendance_time, status)
                VALUES (%s, %s, %s, %s, %s)
            """
            cursor.execute(insert_query, (STUDENT_ID, SESSION_INSTANCE_ID, current_date, current_time_str, "present"))
            conn.commit()
            
            # Update session status to in_progress if needed
            update_session_query = """
                UPDATE session_instances 
                SET status = %s 
                WHERE id = %s AND status = %s
            """
            cursor.execute(update_session_query, ("in_progress", SESSION_INSTANCE_ID, "scheduled"))
            conn.commit()
            
            cursor.close()
            conn.close()
            
            return True, f"Attendance marked successfully at {current_time_str}"
            
        except Exception as e:
            print(f"Database error: {e}")
            return False, f"Database error: {str(e)}"
    
    def check_face_match(self, frame):
        """Check if face in frame matches student profile"""
        if frame is None or not self.reference_faces:
            return False, 0.0, []
            
        try:
            gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
            faces = self.face_cascade.detectMultiScale(
                gray, 
                scaleFactor=1.1, 
                minNeighbors=5, 
                minSize=(30, 30)
            )
            
            best_similarity = 0.0
            match_found = False
            face_locations = []
            
            for (x, y, w, h) in faces:
                # Extract face region
                face_roi = gray[y:y+h, x:x+w]
                face_roi = cv2.resize(face_roi, (100, 100))
                
                # Compare with student profile
                for ref_face in self.reference_faces:
                    is_match, similarity = self.compare_faces(ref_face, face_roi)
                    if is_match and similarity > best_similarity:
                        best_similarity = similarity
                        match_found = True
                
                face_locations.append((x, y, w, h))
            
            return match_found, best_similarity, face_locations
            
        except Exception as e:
            print(f"Error in face matching: {e}")
            return False, 0.0, []
    
    def run(self):
        """Main application loop"""
        if not self.initialize():
            print("Failed to initialize face recognition system")
            return
        
        if not self.reference_faces:
            print("No profile photo found. Please upload your photo first.")
            return
        
        print("\\n" + "="*60)
        print("STUDENT CHECK-IN - FACE RECOGNITION")
        print("="*60)
        print(f"Student ID: {STUDENT_ID}")
        print(f"Session: {SESSION_INSTANCE_ID}")
        print("\\nLook at the camera. When your face is recognized 5 times,")
        print("attendance will be marked automatically.")
        print("Press Q to quit.")
        print("="*60)
        
        while self.running and not self.attendance_marked:
            try:
                ret, frame = self.cap.read()
                
                if not ret or frame is None:
                    print("Failed to read frame")
                    time.sleep(0.1)
                    continue
                
                # Check for face match
                match_found, similarity, face_locations = self.check_face_match(frame)
                
                if match_found:
                    self.match_count += 1
                    status_text = f"FACE MATCHED! ({self.match_count}/{self.required_matches})"
                    status_color = (0, 255, 0)
                    
                    print(f"\\rMatch {self.match_count}/{self.required_matches} - Confidence: {similarity:.2f}", end="", flush=True)
                    
                    if self.match_count >= self.required_matches:
                        print("\\n\\n🎉 FACE VERIFIED! Marking attendance...")
                        success, message = self.mark_attendance()
                        if success:
                            print(f"✅ SUCCESS: {message}")
                            self.attendance_marked = True
                            
                            # Show success screen for 3 seconds
                            for i in range(30):
                                ret, frame = self.cap.read()
                                if ret:
                                    # Draw success message
                                    cv2.rectangle(frame, (0, 0), (frame.shape[1], 120), (0, 255, 0), -1)
                                    cv2.putText(frame, "ATTENDANCE MARKED!", (50, 40), cv2.FONT_HERSHEY_SIMPLEX, 1, (255, 255, 255), 3)
                                    cv2.putText(frame, "You can close this window", (50, 80), cv2.FONT_HERSHEY_SIMPLEX, 0.7, (255, 255, 255), 2)
                                    
                                    # Draw face rectangles
                                    for (x, y, w, h) in face_locations:
                                        cv2.rectangle(frame, (x, y), (x + w, y + h), (0, 255, 0), 3)
                                    
                                    cv2.imshow("Student Check-In", frame)
                                    cv2.waitKey(100)
                            break
                        else:
                            print(f"❌ FAILED: {message}")
                            self.match_count = 0
                else:
                    if self.match_count > 0:
                        self.match_count = max(0, self.match_count - 1)  # Slowly decrease if no match
                    status_text = f"Looking for your face... ({self.match_count}/{self.required_matches})"
                    status_color = (0, 0, 255)
                
                # Draw face rectangles and status
                for (x, y, w, h) in face_locations:
                    color = (0, 255, 0) if match_found else (0, 0, 255)
                    cv2.rectangle(frame, (x, y), (x + w, y + h), color, 2)
                    if match_found:
                        label = f"Student {STUDENT_ID} ({similarity:.2f})"
                        cv2.rectangle(frame, (x, y - 30), (x + w, y), color, cv2.FILLED)
                        cv2.putText(frame, label, (x + 5, y - 10), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 255, 255), 1)
                
                # Display status
                cv2.putText(frame, status_text, (10, 30), cv2.FONT_HERSHEY_SIMPLEX, 0.7, status_color, 2)
                cv2.putText(frame, f"Matches: {self.match_count}/{self.required_matches}", (10, 60), cv2.FONT_HERSHEY_SIMPLEX, 0.6, (255, 255, 255), 2)
                cv2.putText(frame, "Press Q to quit", (10, frame.shape[0] - 20), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 255, 255), 1)
                
                # Display the frame
                cv2.imshow("Student Check-In", frame)
                
                key = cv2.waitKey(1) & 0xFF
                if key == ord("q"):
                    self.running = False
                    break
                    
            except KeyboardInterrupt:
                print("\\nReceived keyboard interrupt")
                break
            except Exception as e:
                print(f"Error in main loop: {e}")
                time.sleep(0.1)
        
        self.cleanup()
    
    def cleanup(self):
        """Clean up resources"""
        try:
            if self.cap is not None:
                self.cap.release()
            cv2.destroyAllWindows()
            print("\\nCheck-in completed.")
        except Exception as e:
            print(f"Error during cleanup: {e}")

# Main execution
if __name__ == "__main__":
    try:
        checkin_system = StudentCheckInRecognition()
        checkin_system.run()
    except Exception as e:
        print(f"Application error: {e}")
    finally:
        cv2.destroyAllWindows()
';
    
    // Write the specialized script
    $script_path = __DIR__ . "/temp_checkin_{$student_id}_{$session_instance_id}.py";
    file_put_contents($script_path, $script_content);
    
    // Launch the face recognition in a new window
    $command = "cd \"" . __DIR__ . "\" && python \"" . basename($script_path) . "\"";
    
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows - start in new window
        $full_command = "start \"Student Check-In\" cmd /c \"$command && pause\"";
        pclose(popen($full_command, 'r'));
    } else {
        // Linux/Mac
        exec($command . ' &');
    }
    
    // Clean up the temporary script after a delay
    register_shutdown_function(function() use ($script_path) {
        sleep(3); // Give time for the script to start
        if (file_exists($script_path)) {
            unlink($script_path);
        }
    });
    
    echo json_encode([
        'success' => true, 
        'message' => 'Face recognition window opened! Look at the camera to check in.',
        'session_title' => $session['session_title'],
        'class_name' => $session['class_name']
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
