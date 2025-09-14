import cv2
import os
import numpy as np
import time
import threading
import mysql.connector
import json
from datetime import datetime

# Configuration
PHOTOS_DIR = "../uploads/profiles"  # Use main system's profile photos
DB_CONFIG = {
    'host': 'localhost',
    'database': 'fullattend_db',
    'user': 'root',
    'password': ''
}

class AttendanceFaceRecognition:
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
        self.last_attendance_time = {}  # Track last attendance time per student
        self.attendance_cooldown = 30  # 30 seconds cooldown between attendance marks
        
    def initialize(self):
        """Initialize the face recognition system"""
        try:
            # Load face cascade classifier
            self.face_cascade = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')
            
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
        """Load reference photos from uploads/profiles directory and detect faces"""
        image_extensions = ['.jpg', '.jpeg', '.png', '.bmp']
        
        print("Loading reference photos from main system...")
        if not os.path.exists(PHOTOS_DIR):
            print(f"Profiles directory not found: {PHOTOS_DIR}")
            return
            
        for filename in os.listdir(PHOTOS_DIR):
            if any(filename.lower().endswith(ext) for ext in image_extensions):
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
                        name = os.path.splitext(filename)[0]
                        self.reference_names.append(name)
                        
                        # Extract student ID from filename
                        try:
                            student_id = int(name)
                            self.reference_student_ids.append(student_id)
                            print(f"✓ Loaded reference photo: {filename} (Student ID: {student_id})")
                        except ValueError:
                            self.reference_student_ids.append(None)
                            print(f"✓ Loaded reference photo: {filename} (No student ID)")
                    else:
                        print(f"✗ No face found in: {filename}")
                        
                except Exception as e:
                    print(f"Error loading {filename}: {e}")
    
    def find_available_cameras(self):
        """Find available camera indices including external USB/webcams"""
        self.available_cameras = []
        self.camera_names = {}
        print("Scanning for available cameras...")
        
        for i in range(10):
            try:
                backends = [cv2.CAP_ANY, cv2.CAP_DSHOW, cv2.CAP_V4L2]
                
                for backend in backends:
                    try:
                        cap_test = cv2.VideoCapture(i, backend)
                        if cap_test.isOpened():
                            cap_test.set(cv2.CAP_PROP_BUFFERSIZE, 1)
                            cap_test.set(cv2.CAP_PROP_FPS, 30)
                            
                            frames_read = 0
                            for _ in range(3):
                                ret, frame = cap_test.read()
                                if ret and frame is not None and frame.size > 0:
                                    frames_read += 1
                                time.sleep(0.1)
                            
                            if frames_read >= 2:
                                if i not in self.available_cameras:
                                    self.available_cameras.append(i)
                                    
                                    width = int(cap_test.get(cv2.CAP_PROP_FRAME_WIDTH))
                                    height = int(cap_test.get(cv2.CAP_PROP_FRAME_HEIGHT))
                                    fps = int(cap_test.get(cv2.CAP_PROP_FPS))
                                    
                                    camera_info = f"Camera {i} ({width}x{height}@{fps}fps)"
                                    self.camera_names[i] = camera_info
                                    print(f"✓ {camera_info}")
                                    break
                            
                            cap_test.release()
                            time.sleep(0.2)
                        else:
                            cap_test.release()
                            
                    except Exception as e:
                        if cap_test:
                            cap_test.release()
                        continue
                        
            except Exception as e:
                continue
        
        self.available_cameras.sort()
        
        if not self.available_cameras:
            print("Warning: No cameras detected!")
        else:
            print(f"\nTotal cameras found: {len(self.available_cameras)}")
    
    def initialize_camera(self, backend=None):
        """Initialize camera with error handling"""
        if not self.available_cameras:
            print("No cameras available!")
            return False
            
        camera_id = self.available_cameras[self.current_camera_index]
        
        try:
            if self.cap is not None:
                self.cap.release()
                time.sleep(0.5)
            
            backends_to_try = [cv2.CAP_DSHOW, cv2.CAP_ANY] if backend is None else [backend]
            
            for backend_type in backends_to_try:
                try:
                    self.cap = cv2.VideoCapture(camera_id, backend_type)
                    
                    if self.cap.isOpened():
                        self.cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
                        self.cap.set(cv2.CAP_PROP_FPS, 30)
                        self.cap.set(cv2.CAP_PROP_FRAME_WIDTH, 640)
                        self.cap.set(cv2.CAP_PROP_FRAME_HEIGHT, 480)
                        
                        successful_reads = 0
                        for attempt in range(5):
                            ret, frame = self.cap.read()
                            if ret and frame is not None and frame.size > 0:
                                successful_reads += 1
                            time.sleep(0.1)
                        
                        if successful_reads >= 3:
                            camera_info = self.camera_names.get(camera_id, f"Camera {camera_id}")
                            print(f"✓ {camera_info} initialized")
                            return True
                        else:
                            self.cap.release()
                            
                except Exception as e:
                    if self.cap:
                        self.cap.release()
                    continue
            
            return False
                
        except Exception as e:
            print(f"Error initializing camera {camera_id}: {e}")
            return False
    
    def switch_camera(self):
        """Switch to next available camera"""
        with self.camera_lock:
            try:
                self.current_camera_index = (self.current_camera_index + 1) % len(self.available_cameras)
                success = self.initialize_camera()
                if success:
                    camera_id = self.available_cameras[self.current_camera_index]
                    print(f"Switched to camera {camera_id}")
                    return True
                else:
                    print("Failed to switch camera")
                    return False
                    
            except Exception as e:
                print(f"Error switching camera: {e}")
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
    
    def mark_attendance(self, student_id):
        """Mark attendance in database"""
        try:
            # Check cooldown period
            current_time = time.time()
            if student_id in self.last_attendance_time:
                time_diff = current_time - self.last_attendance_time[student_id]
                if time_diff < self.attendance_cooldown:
                    return False, f"Cooldown active ({int(self.attendance_cooldown - time_diff)}s remaining)"
            
            # Connect to database
            conn = mysql.connector.connect(**DB_CONFIG)
            cursor = conn.cursor()
            
            # Get current date and time
            now = datetime.now()
            current_date = now.strftime('%Y-%m-%d')
            current_time_str = now.strftime('%H:%M:%S')
            
            # Check if attendance already marked today
            check_query = """
                SELECT id FROM attendance 
                WHERE student_id = %s AND DATE(attendance_date) = %s
            """
            cursor.execute(check_query, (student_id, current_date))
            existing_record = cursor.fetchone()
            
            if existing_record:
                cursor.close()
                conn.close()
                return False, "Attendance already marked today"
            
            # Insert attendance record
            insert_query = """
                INSERT INTO attendance (student_id, attendance_date, attendance_time, status)
                VALUES (%s, %s, %s, 'Present')
            """
            cursor.execute(insert_query, (student_id, current_date, current_time_str))
            conn.commit()
            
            cursor.close()
            conn.close()
            
            # Update last attendance time
            self.last_attendance_time[student_id] = current_time
            
            return True, f"Attendance marked successfully at {current_time_str}"
            
        except Exception as e:
            print(f"Database error: {e}")
            return False, f"Database error: {str(e)}"
    
    def check_face_match(self, frame):
        """Check if any face in the frame matches known faces"""
        if frame is None:
            return []
            
        try:
            gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
            faces = self.face_cascade.detectMultiScale(
                gray, 
                scaleFactor=1.1, 
                minNeighbors=5, 
                minSize=(30, 30)
            )
            
            matches_found = []
            
            for (x, y, w, h) in faces:
                # Extract face region
                face_roi = gray[y:y+h, x:x+w]
                face_roi = cv2.resize(face_roi, (100, 100))
                
                best_match = None
                best_similarity = 0
                best_student_id = None
                
                # Compare with all reference faces
                for i, ref_face in enumerate(self.reference_faces):
                    is_match, similarity = self.compare_faces(ref_face, face_roi)
                    if is_match and similarity > best_similarity:
                        best_similarity = similarity
                        best_match = self.reference_names[i]
                        best_student_id = self.reference_student_ids[i]
                
                # Draw rectangle and label
                if best_match and best_student_id:
                    color = (0, 255, 0)  # Green for match
                    label = f"ID:{best_student_id} ({best_similarity:.2f})"
                    matches_found.append({
                        'name': best_match,
                        'student_id': best_student_id,
                        'similarity': best_similarity
                    })
                else:
                    color = (0, 0, 255)  # Red for unknown
                    label = "Unknown"
                
                cv2.rectangle(frame, (x, y), (x + w, y + h), color, 2)
                cv2.rectangle(frame, (x, y - 30), (x + w, y), color, cv2.FILLED)
                cv2.putText(frame, label, (x + 5, y - 10), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 255, 255), 1)
            
            return matches_found
            
        except Exception as e:
            print(f"Error in face matching: {e}")
            return []
    
    def run(self):
        """Main application loop"""
        if not self.initialize():
            print("Failed to initialize face recognition system")
            return
        
        if not self.reference_faces:
            print("No reference faces loaded. Please add photos to uploads/profiles directory.")
            return
        
        print(f"Loaded {len(self.reference_faces)} reference face(s)")
        print(f"Available cameras: {self.available_cameras}")
        print("\nStarting attendance face recognition...")
        print("Controls:")
        print("- 'q': quit")
        print("- 'a': mark attendance for detected faces")
        print("- 'c': switch to next camera")
        print("- 'r': refresh/rescan cameras")
        print("- 'i': show camera info")
        
        frame_count = 0
        last_camera_check = time.time()
        
        while self.running:
            try:
                with self.camera_lock:
                    if self.cap is None or not self.cap.isOpened():
                        print("Camera not available, trying to reinitialize...")
                        if not self.initialize_camera():
                            time.sleep(1)
                            continue
                    
                    ret, frame = self.cap.read()
                
                if not ret or frame is None:
                    print("Failed to read frame, trying to reinitialize camera...")
                    time.sleep(0.1)
                    if time.time() - last_camera_check > 2:
                        self.initialize_camera()
                        last_camera_check = time.time()
                    continue
                
                frame_count += 1
                
                # Check for face matches (reduce frequency for performance)
                if frame_count % 5 == 0:
                    matches = self.check_face_match(frame)
                    self.last_matches = matches
                else:
                    matches = self.last_matches
                
                # Display status
                status_text = f"FACES DETECTED: {len(matches)}" if matches else "No faces detected"
                status_color = (0, 255, 0) if matches else (0, 0, 255)
                cv2.putText(frame, status_text, (10, 30), cv2.FONT_HERSHEY_SIMPLEX, 1, status_color, 2)
                
                if matches:
                    match_text = f"Students: {', '.join([f'ID{m['student_id']}' for m in matches])}"
                    cv2.putText(frame, match_text, (10, 70), cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 255, 0), 2)
                
                # Display instructions
                cv2.putText(frame, "Press 'A' to mark attendance", (10, frame.shape[0] - 50), cv2.FONT_HERSHEY_SIMPLEX, 0.6, (255, 255, 255), 1)
                cv2.putText(frame, "Press 'Q' to quit", (10, frame.shape[0] - 30), cv2.FONT_HERSHEY_SIMPLEX, 0.6, (255, 255, 255), 1)
                
                # Display current camera info
                camera_id = self.available_cameras[self.current_camera_index] if self.available_cameras else "N/A"
                camera_info = self.camera_names.get(camera_id, f"Camera {camera_id}")
                cv2.putText(frame, camera_info, (10, frame.shape[0] - 10), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 255, 255), 1)
                
                # Display the frame
                cv2.imshow('Attendance Face Recognition - Press Q to quit', frame)
                
                key = cv2.waitKey(1) & 0xFF
                if key == ord('q'):
                    self.running = False
                    break
                elif key == ord('a'):
                    # Mark attendance for detected faces
                    if matches:
                        for match in matches:
                            student_id = match['student_id']
                            if student_id:
                                success, message = self.mark_attendance(student_id)
                                if success:
                                    print(f"✓ ATTENDANCE MARKED: Student ID {student_id} - {message}")
                                else:
                                    print(f"✗ ATTENDANCE FAILED: Student ID {student_id} - {message}")
                    else:
                        print("✗ No faces detected to mark attendance")
                elif key == ord('c'):
                    print("Switching camera...")
                    self.switch_camera()
                elif key == ord('r'):
                    print("Rescanning for cameras...")
                    self.find_available_cameras()
                    if self.available_cameras:
                        self.current_camera_index = 0
                        self.initialize_camera()
                elif key == ord('i'):
                    print("\n=== Camera Information ===")
                    for i, cam_id in enumerate(self.available_cameras):
                        current_marker = " <- CURRENT" if i == self.current_camera_index else ""
                        print(f"  [{i}] {self.camera_names.get(cam_id, f'Camera {cam_id}')}{current_marker}")
                    print("==========================")
                    
            except KeyboardInterrupt:
                print("\nReceived keyboard interrupt")
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
            print("Cleanup completed")
        except Exception as e:
            print(f"Error during cleanup: {e}")

# Main execution
if __name__ == "__main__":
    try:
        attendance_system = AttendanceFaceRecognition()
        attendance_system.run()
    except Exception as e:
        print(f"Application error: {e}")
    finally:
        cv2.destroyAllWindows()
