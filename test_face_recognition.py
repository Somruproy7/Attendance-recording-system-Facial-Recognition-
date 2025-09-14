#!/usr/bin/env python3
"""
Test script for the face recognition attendance system
"""
import os
import sys

# Add the project directory to the path
sys.path.insert(0, os.path.dirname(__file__))

from face_recognition_attendance import load_reference_faces, find_available_cameras

def test_system():
    print("=== Face Recognition Attendance System Test ===\n")
    
    # Test 1: Check if reference photos can be loaded
    print("1. Testing reference photo loading...")
    try:
        reference_faces, reference_names, reference_student_ids = load_reference_faces()
        if reference_faces:
            print(f"   ✓ Successfully loaded {len(reference_faces)} reference faces")
            for i, (name, student_id) in enumerate(zip(reference_names, reference_student_ids)):
                print(f"   - {name}: Student ID {student_id}")
        else:
            print("   ⚠ No reference faces found in uploads/profiles directory")
            print("   Please add student photos named with their ID (e.g., '123.jpg')")
    except Exception as e:
        print(f"   ✗ Error loading reference photos: {e}")
    
    print()
    
    # Test 2: Check camera availability
    print("2. Testing camera availability...")
    try:
        cameras = find_available_cameras()
        if cameras:
            print(f"   ✓ Found {len(cameras)} available camera(s): {cameras}")
        else:
            print("   ⚠ No cameras found")
    except Exception as e:
        print(f"   ✗ Error checking cameras: {e}")
    
    print()
    
    # Test 3: Check required directories
    print("3. Testing directory structure...")
    base_dir = os.path.dirname(__file__)
    uploads_dir = os.path.join(base_dir, 'uploads')
    profiles_dir = os.path.join(base_dir, 'uploads', 'profiles')
    
    if os.path.exists(uploads_dir):
        print("   ✓ uploads/ directory exists")
    else:
        print("   ⚠ uploads/ directory missing")
        
    if os.path.exists(profiles_dir):
        print("   ✓ uploads/profiles/ directory exists")
        files = [f for f in os.listdir(profiles_dir) if f.lower().endswith(('.jpg', '.jpeg', '.png', '.bmp'))]
        print(f"   ✓ Found {len(files)} image files in profiles directory")
    else:
        print("   ⚠ uploads/profiles/ directory missing")
        print("   Creating directory...")
        try:
            os.makedirs(profiles_dir, exist_ok=True)
            print("   ✓ Directory created")
        except Exception as e:
            print(f"   ✗ Failed to create directory: {e}")
    
    print()
    
    # Test 4: Check Python dependencies
    print("4. Testing Python dependencies...")
    try:
        import cv2
        print(f"   ✓ OpenCV version: {cv2.__version__}")
    except ImportError:
        print("   ✗ OpenCV not installed. Run: pip install opencv-python")
    
    try:
        import numpy
        print(f"   ✓ NumPy version: {numpy.__version__}")
    except ImportError:
        print("   ✗ NumPy not installed. Run: pip install numpy")
    
    try:
        import mysql.connector
        print("   ✓ MySQL connector available")
    except ImportError:
        print("   ✗ MySQL connector not installed. Run: pip install mysql-connector-python")
    
    print("\n=== Test Complete ===")
    print("\nTo use the system:")
    print("1. Add student photos to uploads/profiles/ named with student IDs (e.g., '123.jpg')")
    print("2. Students can click 'Start Face Recognition' on the attendance page")
    print("3. When 'MATCH FOUND' appears, attendance is automatically marked")

if __name__ == '__main__':
    test_system()
