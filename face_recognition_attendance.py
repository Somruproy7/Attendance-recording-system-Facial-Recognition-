import cv2
import os
import numpy as np
import json
import mysql.connector
import sys
import time
import threading
from datetime import datetime
from recognize_face import RobustFaceRecognition


# Database configuration
DB_CONFIG = {
    'host': 'localhost',
    'database': 'fullattend_db',
    'user': 'root',
    'password': ''
}


class AttendanceFaceRecognition(RobustFaceRecognition):
    """Extended face recognition class with attendance marking capabilities"""
    
    def __init__(self, session_instance_id=None):
        super().__init__()
        self.session_instance_id = session_instance_id
        self.attendance_marked = set()  # Track which students have already been marked
    
    def mark_attendance(self, student_id):
        """Mark attendance in the database"""
        try:
            conn = mysql.connector.connect(**DB_CONFIG)
            cursor = conn.cursor()
            
            if self.session_instance_id:
                # Mark attendance for specific session
                query = """
                    INSERT INTO attendance_records (student_id, session_instance_id, status, check_in_time) 
                    VALUES (%s, %s, 'present', NOW())
                    ON DUPLICATE KEY UPDATE status = 'present', check_in_time = NOW()
                """
                cursor.execute(query, (student_id, self.session_instance_id))
            else:
                # Find current active session for this student
                query = """
                    SELECT si.id 
                    FROM student_enrollments se
                    JOIN classes c ON se.class_id = c.id
                    JOIN timetable_sessions ts ON ts.class_id = c.id
                    JOIN session_instances si ON si.timetable_session_id = ts.id AND si.session_date = CURDATE()
                    WHERE se.student_id = %s 
                      AND se.status = 'enrolled' 
                      AND c.status = 'active'
                      AND si.status = 'in_progress'
                      AND NOW() BETWEEN CONCAT(si.session_date, ' ', ts.start_time) 
                      AND CONCAT(si.session_date, ' ', ts.end_time)
                    LIMIT 1
                """
                cursor.execute(query, (student_id,))
                result = cursor.fetchone()
                
                if result:
                    session_id = result[0]
                    query = """
                        INSERT INTO attendance_records (student_id, session_instance_id, status, check_in_time) 
                        VALUES (%s, %s, 'present', NOW())
                        ON DUPLICATE KEY UPDATE status = 'present', check_in_time = NOW()
                    """
                    cursor.execute(query, (student_id, session_id))
            
            conn.commit()
            cursor.close()
            conn.close()
            return True
        except Exception as e:
            print(f"Database error: {e}")
            return False
    
    def check_face_match_with_attendance(self, frame):
        """Check for face matches and return student IDs for attendance marking"""
        if frame is None:
            return [], [], []
            
        try:
            gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
            faces = self.face_cascade.detectMultiScale(
                gray, 
                scaleFactor=1.1, 
                minNeighbors=5, 
                minSize=(30, 30)
            )
            
            matches_found = []
            matched_student_ids = []
            confidences = []
            
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
                    label = f"Student {best_student_id} ({best_similarity:.2f})"
                    matches_found.append(best_match)
                    matched_student_ids.append(best_student_id)
                    confidences.append(best_similarity)
                else:
                    color = (0, 0, 255)  # Red for unknown
                    label = "Unknown"
                
                cv2.rectangle(frame, (x, y), (x + w, y + h), color, 2)
                cv2.rectangle(frame, (x, y - 30), (x + w, y), color, cv2.FILLED)
                cv2.putText(frame, label, (x + 5, y - 10), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 255, 255), 1)
            
            return matches_found, matched_student_ids, confidences
            
        except Exception as e:
            print(f"Error in face matching: {e}")
            return [], [], []
    
    def run_attendance_mode(self):
        """Run face recognition with automatic attendance marking"""
        if not self.initialize():
            print("Failed to initialize face recognition system")
            return
        
        if not self.reference_faces:
            print("No reference faces loaded. Please add photos to the uploads/profiles directory.")
            return
        
        print(f"Loaded {len(self.reference_faces)} reference face(s)")
        print(f"Available cameras: {self.available_cameras}")
        print("\nStarting face recognition for attendance...")
        print("Controls:")
        print("- 'q': quit")
        print("- 's': capture and check for matches")
        print("- 'c': switch to next camera")
        print("- '0-9': switch to specific camera")
        print("- 'r': refresh/rescan cameras")
        print("- 'i': show camera info")
        print("\nWhen MATCH FOUND appears, attendance will be automatically marked!")
        
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
                
                # Check for face matches with attendance marking
                if frame_count % 5 == 0:  # Process every 5th frame
                    matches, student_ids, confidences = self.check_face_match_with_attendance(frame)
                    
                    # Auto-mark attendance for matched students
                    for i, student_id in enumerate(student_ids):
                        if student_id and student_id not in self.attendance_marked:
                            if self.mark_attendance(student_id):
                                print(f"✓ SUCCESS: Attendance marked for Student {student_id} (confidence: {confidences[i]:.2f})")
                                self.attendance_marked.add(student_id)
                            else:
                                print(f"✗ ERROR: Failed to mark attendance for Student {student_id}")
                    
                    self.last_matches = matches
                else:
                    matches = self.last_matches
                
                # Display status
                status_text = "MATCH FOUND!" if matches else "No match"
                status_color = (0, 255, 0) if matches else (0, 0, 255)
                cv2.putText(frame, status_text, (10, 30), cv2.FONT_HERSHEY_SIMPLEX, 1, status_color, 2)
                
                if matches:
                    match_text = f"Matched: {', '.join([f'Student {sid}' for sid in self.attendance_marked if sid])}"
                    cv2.putText(frame, match_text, (10, 70), cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 255, 0), 2)
                
                # Display current camera info
                camera_id = self.available_cameras[self.current_camera_index] if self.available_cameras else "N/A"
                camera_info = self.camera_names.get(camera_id, f"Camera {camera_id}")
                cv2.putText(frame, camera_info, (10, frame.shape[0] - 30), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 255, 255), 1)
                cv2.putText(frame, f"Total cameras: {len(self.available_cameras)}", (10, frame.shape[0] - 10), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 255, 255), 1)
                
                # Display the frame
                cv2.imshow('Face Recognition Attendance - Press Q to quit', frame)
                
                key = cv2.waitKey(1) & 0xFF
                if key == ord('q'):
                    self.running = False
                    break
                elif key == ord('s'):
                    # Manual capture and check
                    matches, student_ids, confidences = self.check_face_match_with_attendance(frame)
                    if matches and student_ids:
                        for i, student_id in enumerate(student_ids):
                            if student_id:
                                if self.mark_attendance(student_id):
                                    print(f"✓ SUCCESS: Attendance marked for Student {student_id} (confidence: {confidences[i]:.2f})")
                                    self.attendance_marked.add(student_id)
                                else:
                                    print(f"✗ ERROR: Failed to mark attendance for Student {student_id}")
                    else:
                        print("✗ NO MATCH: Face not recognized")
                elif key == ord('c'):
                    print("Switching camera...")
                    self.switch_camera()
                elif key >= ord('0') and key <= ord('9'):
                    requested_camera = int(chr(key))
                    print(f"Switching to camera {requested_camera}...")
                    self.switch_camera(requested_camera)
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


# Legacy functions removed - now using AttendanceFaceRecognition class


def main():
    # Get session instance ID from command line if provided
    session_instance_id = None
    if len(sys.argv) > 1:
        try:
            session_instance_id = int(sys.argv[1])
        except ValueError:
            pass

    # Create and run the attendance face recognition system
    try:
        attendance_system = AttendanceFaceRecognition(session_instance_id)
        attendance_system.run_attendance_mode()
    except Exception as e:
        print(f"Application error: {e}")
    finally:
        cv2.destroyAllWindows()


if __name__ == '__main__':
    main()
