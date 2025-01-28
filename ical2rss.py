#!/usr/bin/env python3
import sys
import datetime
import xml.etree.ElementTree as ET
from xml.dom import minidom
from icalendar import Calendar
import tzlocal
import hashlib
import uuid
import argparse

def format_time(dt):
    """Format datetime to German time string"""
    if isinstance(dt, datetime.datetime):
        # Convert to local timezone
        local_tz = tzlocal.get_localzone()
        if dt.tzinfo is None:
            dt = dt.replace(tzinfo=datetime.timezone.utc)
        local_time = dt.astimezone(local_tz)
        return local_time.strftime("%H:%M")
    return ""

def format_description(event):
    """Format event description with time and location"""
    description_parts = []
    
    # Add time and location
    start_time = format_time(event.get('DTSTART').dt if event.get('DTSTART') else None)
    if start_time:
        location = str(event.get('LOCATION', ''))
        if location:
            description_parts.append(f"{start_time} Uhr / {location}")
        else:
            description_parts.append(f"{start_time} Uhr")
    
    # Add event description if available
    if event.get('DESCRIPTION'):
        description_parts.append(f"/ {str(event.get('DESCRIPTION'))}")
    
    return "\n\n".join(description_parts)

def get_event_start(event):
    """Get datetime of event for sorting"""
    if event.get('DTSTART'):
        start = event.get('DTSTART').dt
        if isinstance(start, datetime.date) and not isinstance(start, datetime.datetime):
            start = datetime.datetime.combine(start, datetime.time.min, tzinfo=datetime.timezone.utc)
        elif start.tzinfo is None:
            start = start.replace(tzinfo=datetime.timezone.utc)
        return start
    return datetime.datetime.max.replace(tzinfo=datetime.timezone.utc)

def get_event_guid(component, random=False):
    """Generate a GUID for the event
    If random is True, generates a random UUID
    Otherwise uses SHA1 hash of event content
    """
    if random:
        return str(uuid.uuid4())
    
    # Convert the component back to iCal format to get the complete content
    event_str = component.to_ical().decode('utf-8')
    
    # Calculate SHA1 hash
    return hashlib.sha1(event_str.encode('utf-8')).hexdigest()

def create_rss_feed(random_guid=False, channel_title='Termine', channel_link='https://example.com', channel_description='Terminkalender'):
    # Create RSS structure
    rss = ET.Element('rss', version='2.0')
    channel = ET.SubElement(rss, 'channel')
    
    # Add required channel elements
    ET.SubElement(channel, 'title').text = channel_title
    ET.SubElement(channel, 'link').text = channel_link
    ET.SubElement(channel, 'description').text = channel_description
    
    # Read all input
    input_data = sys.stdin.read()
    
    # Split into individual VCALENDAR blocks
    calendar_blocks = input_data.split('BEGIN:VCALENDAR')
    
    # Collect all events
    all_events = []
    
    # Process each calendar block
    for block in calendar_blocks:
        if not block.strip():
            continue
            
        # Reconstruct valid iCal format
        calendar_data = 'BEGIN:VCALENDAR' + block
        
        try:
            cal = Calendar.from_ical(calendar_data)
            
            # Collect events from this calendar
            for component in cal.walk('VEVENT'):
                all_events.append(component)
        except Exception as e:
            sys.stderr.write(f"Error processing calendar block: {str(e)}\n")
    
    # Sort events by start time
    all_events.sort(key=get_event_start)
    
    # Process sorted events
    for component in all_events:
        item = ET.SubElement(channel, 'item')
        
        # Title
        ET.SubElement(item, 'title').text = str(component.get('SUMMARY', 'Untitled Event'))
        
        # Description
        ET.SubElement(item, 'description').text = format_description(component)
        
        # Link
        ET.SubElement(item, 'link').text = 'https://example.com'
        
        # GUID
        ET.SubElement(item, 'guid', isPermaLink='false').text = get_event_guid(component, random_guid)
        
        # PubDate (using event start time)
        if component.get('DTSTART'):
            start = component.get('DTSTART').dt
            if isinstance(start, datetime.date) and not isinstance(start, datetime.datetime):
                start = datetime.datetime.combine(start, datetime.time.min, tzinfo=datetime.timezone.utc)
            elif start.tzinfo is None:
                start = start.replace(tzinfo=datetime.timezone.utc)
            ET.SubElement(item, 'pubDate').text = start.strftime('%a, %d %b %Y %H:%M:%S +0000')
    
    # Pretty print the XML
    xmlstr = minidom.parseString(ET.tostring(rss, encoding='unicode')).toprettyxml(indent="  ")
    # Remove empty lines
    return '\n'.join(line for line in xmlstr.split('\n') if line.strip())

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description='Convert iCal to RSS feed')
    parser.add_argument('--rand-guid', action='store_true', help='Generate random GUIDs instead of content-based hashes')
    parser.add_argument('--channel-title', default='Termine', help='Title for the RSS channel')
    parser.add_argument('--channel-link', default='https://example.com', help='Link for the RSS channel')
    parser.add_argument('--channel-description', default='Terminkalender', help='Description for the RSS channel')
    args = parser.parse_args()
    
    print(create_rss_feed(
        random_guid=args.rand_guid,
        channel_title=args.channel_title,
        channel_link=args.channel_link,
        channel_description=args.channel_description
    )) 