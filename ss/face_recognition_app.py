import cv2
import face_recognition
import os
import numpy as np

# Directory containing reference photos
PHOTOS_DIR = "."  # Current directory where your photos are stored

def load_reference_faces():
    """Load and encode all reference photos from the directory"""
    known_face_encodings = []
    known_face_names = []
    
    # Supported image extensions
    image_extensions = ['.jpg', '.jpeg', '.png', '.bmp']
    
    print("Loading reference photos...")
    for filename in os.listdir(PHOTOS_DIR):
        if any(filename.lower().endswith(ext) for ext in image_extensions):
            image_path = os.path.join(PHOTOS_DIR, filename)
            
            # Load image and get face encoding
            image = face_recognition.load_image_file(image_path)
            face_encodings = face_recognition.face_encodings(image)
            
            if face_encodings:
                known_face_encodings.append(face_encodings[0])
                # Use filename without extension as name
                name = os.path.splitext(filename)[0]
                known_face_names.append(name)
                print(f"✓ Loaded reference photo: {filename}")
            else:
                print(f"✗ No face found in: {filename}")
    
    return known_face_encodings, known_face_names

def check_face_match(frame, known_face_encodings, known_face_names):
    """Check if any face in the frame matches known faces"""
    # Convert BGR to RGB (face_recognition uses RGB)
    rgb_frame = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
    
    # Find face locations and encodings in current frame
    face_locations = face_recognition.face_locations(rgb_frame)
    face_encodings = face_recognition.face_encodings(rgb_frame, face_locations)
    
    matches_found = []
    
    for face_encoding, face_location in zip(face_encodings, face_locations):
        # Compare with known faces
        matches = face_recognition.compare_faces(known_face_encodings, face_encoding, tolerance=0.6)
        face_distances = face_recognition.face_distance(known_face_encodings, face_encoding)
        
        name = "Unknown"
        color = (0, 0, 255)  # Red for unknown
        
        if True in matches:
            # Find the best match
            best_match_index = np.argmin(face_distances)
            if matches[best_match_index]:
                name = known_face_names[best_match_index]
                color = (0, 255, 0)  # Green for match
                matches_found.append(name)
        
        # Draw rectangle and label
        top, right, bottom, left = face_location
        cv2.rectangle(frame, (left, top), (right, bottom), color, 2)
        cv2.rectangle(frame, (left, bottom - 35), (right, bottom), color, cv2.FILLED)
        cv2.putText(frame, name, (left + 6, bottom - 6), cv2.FONT_HERSHEY_DUPLEX, 0.6, (255, 255, 255), 1)
    
    return matches_found

# Load reference faces
try:
    known_face_encodings, known_face_names = load_reference_faces()
    if not known_face_encodings:
        print("No reference faces loaded. Please add photos to the directory.")
        exit()
    print(f"Loaded {len(known_face_encodings)} reference face(s)")
except Exception as e:
    print(f"Error loading reference photos: {e}")
    exit()

# Open the default webcam
cap = cv2.VideoCapture(0)

if not cap.isOpened():
    print("Error: Could not open webcam.")
    exit()

print("\nStarting face recognition...")
print("Press 'q' to quit, 's' to capture and check for matches")

while True:
    # Read a frame from the webcam
    ret, frame = cap.read()

    if not ret:
        print("Error: Could not read frame.")
        break

    # Check for face matches in real-time
    matches = check_face_match(frame, known_face_encodings, known_face_names)
    
    # Display status
    status_text = "MATCH FOUND!" if matches else "No match"
    status_color = (0, 255, 0) if matches else (0, 0, 255)
    cv2.putText(frame, status_text, (10, 30), cv2.FONT_HERSHEY_SIMPLEX, 1, status_color, 2)
    
    if matches:
        match_text = f"Matched: {', '.join(matches)}"
        cv2.putText(frame, match_text, (10, 70), cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 255, 0), 2)

    # Display the frame
    cv2.imshow('Face Recognition - Press Q to quit, S to capture', frame)

    key = cv2.waitKey(1) & 0xFF
    if key == ord('q'):
        break
    elif key == ord('s'):
        # Capture and check
        matches = check_face_match(frame, known_face_encodings, known_face_names)
        if matches:
            print(f"✓ SUCCESS: Face matched with {', '.join(matches)}")
        else:
            print("✗ NO MATCH: Face not recognized")

# Release the webcam and destroy all OpenCV windows
cap.release()
cv2.destroyAllWindows()
