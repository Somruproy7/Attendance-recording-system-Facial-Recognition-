<?php
// ss/student_face_recognition.php
session_start();
require_once '../config/database.php';

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
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
        SELECT si.id, si.status, ts.session_title, c.class_name
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
    
    // Create a specialized attendance script for this student and session
    $script_content = '
import cv2
import os
import numpy as np
import time
import threading
import mysql.connector
import json
from datetime import datetime

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

class StudentAttendanceRecognition:
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
        self.attendance_marked = False
        
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
            
        # Look for this specific student\'s photo
        target_filename = f"{STUDENT_ID}"
        
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
            
            # Check if attendance already marked for this session
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
            return False, 0.0
            
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
                
                # Draw rectangle and label
                if match_found:
                    color = (0, 255, 0)  # Green for match
                    label = f"Student ID: {STUDENT_ID} ({best_similarity:.2f})"
                else:
                    color = (0, 0, 255)  # Red for no match
                    label = "Not recognized"
                
                cv2.rectangle(frame, (x, y), (x + w, y + h), color, 2)
                cv2.rectangle(frame, (x, y - 30), (x + w, y), color, cv2.FILLED)
                cv2.putText(frame, label, (x + 5, y - 10), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 255, 255), 1)
            
            return match_found, best_similarity
            
        except Exception as e:
            print(f"Error in face matching: {e}")
            return False, 0.0
    
    def run(self):
        """Main application loop"""
        if not self.initialize():
            print("Failed to initialize face recognition system")
            return
        
        if not self.reference_faces:
            print("No profile photo found. Please upload your photo first.")
            return
        
        print("\\n" + "="*60)
        print("STUDENT ATTENDANCE CHECK-IN")
        print("="*60)
        print(f"Student ID: {STUDENT_ID}")
        print(f"Session: {SESSION_INSTANCE_ID}")
        print("\\nInstructions:")
        print("- Look directly at the camera")
        print("- Press SPACE when your face is detected to mark attendance")
        print("- Press Q to quit")
        print("="*60)
        
        consecutive_matches = 0
        required_matches = 3  # Need 3 consecutive matches for stability
        
        while self.running and not self.attendance_marked:
            try:
                ret, frame = self.cap.read()
                
                if not ret or frame is None:
                    print("Failed to read frame")
                    time.sleep(0.1)
                    continue
                
                # Check for face match
                match_found, similarity = self.check_face_match(frame)
                
                if match_found:
                    consecutive_matches += 1
                    status_text = f"FACE DETECTED! ({consecutive_matches}/{required_matches})"
                    status_color = (0, 255, 0)
                    
                    if consecutive_matches >= required_matches:
                        status_text = "READY! Press SPACE to mark attendance"
                        # Auto-mark attendance after stable detection
                        success, message = self.mark_attendance()
                        if success:
                            print(f"\\n✓ SUCCESS: {message}")
                            self.attendance_marked = True
                            # Show success for 3 seconds
                            for i in range(30):
                                ret, frame = self.cap.read()
                                if ret:
                                    cv2.putText(frame, "ATTENDANCE MARKED!", (50, 50), cv2.FONT_HERSHEY_SIMPLEX, 1, (0, 255, 0), 3)
                                    cv2.putText(frame, "You can close this window", (50, 100), cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 255, 0), 2)
                                    cv2.imshow("Student Check-In", frame)
                                    cv2.waitKey(100)
                            break
                        else:
                            print(f"\\n✗ FAILED: {message}")
                            consecutive_matches = 0
                else:
                    consecutive_matches = 0
                    status_text = "Looking for your face..."
                    status_color = (0, 0, 255)
                
                # Display status
                cv2.putText(frame, status_text, (10, 30), cv2.FONT_HERSHEY_SIMPLEX, 0.7, status_color, 2)
                cv2.putText(frame, "Press Q to quit", (10, frame.shape[0] - 20), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 255, 255), 1)
                
                # Display the frame
                cv2.imshow("Student Check-In", frame)
                
                key = cv2.waitKey(1) & 0xFF
                if key == ord("q"):
                    self.running = False
                    break
                elif key == ord(" ") and consecutive_matches >= required_matches:
                    # Manual attendance marking
                    success, message = self.mark_attendance()
                    if success:
                        print(f"\\n✓ SUCCESS: {message}")
                        self.attendance_marked = True
                        break
                    else:
                        print(f"\\n✗ FAILED: {message}")
                    
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
            print("\\nCheck-in completed. You can close this window.")
        except Exception as e:
            print(f"Error during cleanup: {e}")

# Main execution
if __name__ == "__main__":
    try:
        attendance_system = StudentAttendanceRecognition()
        attendance_system.run()
    except Exception as e:
        print(f"Application error: {e}")
    finally:
        cv2.destroyAllWindows()
';
    
    // Write the specialized script
    $script_path = __DIR__ . "/temp_student_recognition_{$student_id}_{$session_instance_id}.py";
    file_put_contents($script_path, $script_content);
    
    // Launch the face recognition
    $command = "cd \"" . __DIR__ . "\" && python \"" . basename($script_path) . "\" 2>&1";
    
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows - start in new window
        $full_command = "start \"Student Check-In\" cmd /k \"$command\"";
        pclose(popen($full_command, 'r'));
    } else {
        // Linux/Mac
        exec($command . ' &');
    }
    
    // Clean up the temporary script after a delay
    register_shutdown_function(function() use ($script_path) {
        if (file_exists($script_path)) {
            sleep(2); // Give time for the script to start
            unlink($script_path);
        }
    });
    
    echo json_encode([
        'success' => true, 
        'message' => 'Face recognition started! Look at the camera window to check in.',
        'session_title' => $session['session_title'],
        'class_name' => $session['class_name']
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
